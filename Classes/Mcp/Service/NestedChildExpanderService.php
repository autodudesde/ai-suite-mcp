<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;

/**
 * Models describe an accordion the way it reads: the items nested inside the parent's inline
 * field. DataHandler cannot take that shape. It wants every child as its own row pointing back
 * at the parent, and an array value that reaches it dies with "Array to string conversion" deep
 * inside checkValue_SW() — an error the model cannot act on.
 *
 * Everything needed to rewrite the payload sits in the TCA (foreign_table, foreign_field,
 * foreign_match_fields), so the expansion is mechanical rather than guesswork. It covers FAL
 * fields too: there the match fields carry the tablenames/fieldname pair.
 */
final class NestedChildExpanderService
{
    /**
     * `file` is the dedicated TCA type for FAL fields (tt_content.image, assets, …). TcaPreparation
     * fills in the same relation keys inline uses, so both expand through one code path.
     */
    private const EXPANDABLE_TYPES = ['inline', 'file'];

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
     * Rewrites nested inline children into sibling records placed right after their parent.
     *
     * @param array<int, mixed> $records
     *
     * @return array<int, mixed>
     */
    public function expand(array $records): array
    {
        $plans = [];
        foreach (array_values($records) as $record) {
            $plans[] = $this->planRecord($record);
        }

        // `$ref:N` is index based, and inserting children shifts every later record. Map the
        // caller's indices onto the expanded ones BEFORE injecting any reference of our own,
        // otherwise a caller-supplied $ref would silently point at the wrong record.
        $indexMap = [];
        $shifted = 0;
        foreach ($plans as $originalIndex => $plan) {
            $indexMap[$originalIndex] = $shifted;
            $shifted += 1 + count($plan['children']);
        }

        $expanded = [];
        foreach ($plans as $plan) {
            $record = $plan['record'];
            if (is_array($record) && isset($record['fields']) && is_array($record['fields'])) {
                $record['fields'] = $this->remapReferences($record['fields'], $indexMap);
            }

            $parentIndex = count($expanded);
            $expanded[] = $record;

            foreach ($plan['children'] as $child) {
                $fields = $this->remapReferences($child['fields'], $indexMap);
                // Match fields and the parent pointer are derived from the TCA, so they win over
                // anything the model may have put in the nested object.
                $fields = array_merge($fields, $child['matchFields']);
                $fields[$child['parentField']] = '$ref:'.$parentIndex;

                $expanded[] = [
                    'table' => $child['table'],
                    'pid' => $child['pid'],
                    'fields' => $fields,
                ];
            }
        }

        return $expanded;
    }

    /**
     * @return array{record: mixed, children: list<array{table: string, pid: int, parentField: string, matchFields: array<string, mixed>, fields: array<string, mixed>}>}
     */
    private function planRecord(mixed $record): array
    {
        if (!is_array($record)) {
            return ['record' => $record, 'children' => []];
        }

        $table = (string) ($record['table'] ?? '');
        $fields = $record['fields'] ?? [];
        if ('' === $table || !is_array($fields) || [] === $fields) {
            return ['record' => $record, 'children' => []];
        }

        // A `children` key on the record level is the savePageTree shape, misapplied. writeRecords
        // has no such key, so it would be silently dropped and the child rows (accordion items, card
        // group cards) would vanish without a trace. Refuse it and name the field they belong in.
        if (array_key_exists('children', $record)) {
            throw new InvalidParameterException($this->misplacedChildrenMessage($table, $fields));
        }

        $children = [];
        foreach ($fields as $field => $value) {
            $field = (string) $field;
            if (!$this->isListOfObjects($value)) {
                continue;
            }

            $config = $this->tcaCompatibilityService->getFieldConfiguration($table, $field);
            if (!in_array($config['type'] ?? '', self::EXPANDABLE_TYPES, true)) {
                // No child rows to create for this field. Left in place on purpose: WriteRecordTool
                // rejects it with a message naming the field.
                continue;
            }

            $childTable = (string) ($config['foreign_table'] ?? '');
            $parentField = (string) ($config['foreign_field'] ?? '');
            if ('' === $childTable || '' === $parentField) {
                throw new InvalidParameterException(sprintf(
                    '`%s` is an inline field without a `foreign_field` in its TCA, so its children cannot be derived. Write them as their own records.',
                    $field,
                ));
            }

            // Children inherit the parent's pid, and an update has no pid to inherit. Appending
            // to an existing record is also ambiguous (replace or add?), so it stays explicit.
            if (isset($record['uid'])) {
                throw new InvalidParameterException(sprintf(
                    'Nested children in `%s` are only expanded when the parent is created. To attach children to the existing record %s:%d, write each child as its own record with `%s`: %d and its own `pid`.',
                    $field,
                    $table,
                    (int) $record['uid'],
                    $parentField,
                    (int) $record['uid'],
                ));
            }
            if (!isset($record['pid'])) {
                throw new InvalidParameterException(sprintf(
                    'Nested children in `%s` need the parent `pid` to inherit, but the record has none.',
                    $field,
                ));
            }

            /** @var array<string, mixed> $matchFields */
            $matchFields = is_array($config['foreign_match_fields'] ?? null)
                ? $config['foreign_match_fields']
                : [];

            // A polymorphic child (every FAL reference is one) stores the parent's table name in
            // the column named by foreign_table_field. DataHandler fills that in itself when the
            // children ride along in an inline datamap, but these are standalone records.
            $tableField = (string) ($config['foreign_table_field'] ?? '');
            if ('' !== $tableField) {
                $matchFields[$tableField] = $table;
            }

            unset($fields[$field]);

            foreach ($value as $childFields) {
                $children[] = [
                    'table' => $childTable,
                    'pid' => (int) $record['pid'],
                    'parentField' => $parentField,
                    'matchFields' => $matchFields,
                    'fields' => $this->unwrapChildFields($childFields),
                ];
            }
        }

        $record['fields'] = $fields;

        return ['record' => $record, 'children' => $children];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function misplacedChildrenMessage(string $table, array $fields): string
    {
        $inlineField = $this->firstInlineField($table, $fields);
        $where = null !== $inlineField
            ? sprintf('Nest the child objects inside the `%s` field of `fields`', $inlineField)
            : 'Nest the child objects inside the parent\'s inline field, inside `fields`';

        return sprintf(
            'A `children` key on the record level is not valid for writeRecords (that is savePageTree syntax). '
                .'%s — not as a top-level `children`. Call listContentTypes to see the inline field name for this element.',
            $where,
        );
    }

    /**
     * The name of the first inline/file collection field of the record's type, so the error can point
     * at it (tt_content card_group -> tx_bootstrappackage_card_group_item, for instance).
     *
     * @param array<string, mixed> $fields
     */
    private function firstInlineField(string $table, array $fields): ?string
    {
        try {
            $typeKey = $this->tcaCompatibilityService->resolveSubSchemaType($table, $fields);
            foreach ($this->tcaCompatibilityService->getFieldNamesForType($table, $typeKey) as $fieldName) {
                $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($table, $typeKey, $fieldName);
                if (in_array($config['type'] ?? '', self::EXPANDABLE_TYPES, true) && !empty($config['foreign_table'])) {
                    return $fieldName;
                }
            }
        } catch (\Throwable) {
            // Best-effort: the message still works without the concrete field name.
        }

        return null;
    }

    /**
     * A nested child may arrive either as a bare field map or wrapped in the same envelope the
     * top-level records use ({fields: {...}}). The schema describes records as {table, fields, ...},
     * so mirroring that shape for children is the obvious reading, and models do exactly that.
     * Rejecting it produced "Unknown field(s): fields" and sent them off writing children one by one.
     *
     * @param array<string, mixed> $child
     *
     * @return array<string, mixed>
     */
    private function unwrapChildFields(array $child): array
    {
        if (!is_array($child['fields'] ?? null)) {
            return $child;
        }

        // Only an envelope, never a child that happens to own a field called `fields`: everything
        // beside it must be a record-level key.
        $envelopeKeys = ['fields', 'table', 'pid', 'uid', 'position'];
        if ([] !== array_diff(array_keys($child), $envelopeKeys)) {
            return $child;
        }

        // @var array<string, mixed> $fields
        return $child['fields'];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int, int>      $indexMap
     *
     * @return array<string, mixed>
     */
    private function remapReferences(array $fields, array $indexMap): array
    {
        foreach ($fields as $field => $value) {
            if (is_string($value) && preg_match('/^\$ref:(\d+)$/', $value, $matches)) {
                $original = (int) $matches[1];
                if (isset($indexMap[$original])) {
                    $fields[$field] = '$ref:'.$indexMap[$original];
                }
            }
        }

        return $fields;
    }

    private function isListOfObjects(mixed $value): bool
    {
        if (!is_array($value) || [] === $value || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                return false;
            }
        }

        return true;
    }
}
