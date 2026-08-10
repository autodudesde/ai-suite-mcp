<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Utility\BatchIndexRemapper;

final class NestedChildExpanderService
{
    private const EXPANDABLE_TYPES = ['inline', 'file'];

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
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

        // Remap the caller's `$ref:N` indices before injecting references of our own.
        $indexMap = BatchIndexRemapper::buildIndexMap(
            array_map(static fn (array $plan): int => count($plan['children']), $plans),
        );

        $expanded = [];
        foreach ($plans as $plan) {
            $record = $plan['record'];
            if (is_array($record) && isset($record['fields']) && is_array($record['fields'])) {
                $record['fields'] = BatchIndexRemapper::remap($record['fields'], $indexMap);
            }

            $parentIndex = count($expanded);
            $expanded[] = $record;

            foreach ($plan['children'] as $child) {
                $fields = BatchIndexRemapper::remap($child['fields'], $indexMap);
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
     * @param array<string, mixed> $child
     *
     * @return array<string, mixed>
     */
    private function unwrapChildFields(array $child): array
    {
        if (!is_array($child['fields'] ?? null)) {
            return $child;
        }

        $envelopeKeys = ['fields', 'table', 'pid', 'uid', 'position'];
        if ([] !== array_diff(array_keys($child), $envelopeKeys)) {
            return $child;
        }

        // @var array<string, mixed> $fields
        return $child['fields'];
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
