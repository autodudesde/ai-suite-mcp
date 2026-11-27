<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Utility\BatchIndexRemapper;

/**
 * @phpstan-type Child array{table: string, pid: int, parentField: string, parentOffset: int, matchFields: array<string, mixed>, fields: array<string, mixed>}
 */
final class NestedChildExpanderService
{
    private const EXPANDABLE_TYPES = ['inline', 'file'];

    private const MAX_DEPTH = 3;

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly RecordAccessService $recordAccess,
        private readonly ChildRecordTemplate $childRecordTemplate,
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
            if (is_array($record) && is_array($record[TranslationExpanderService::LOCALIZE_KEY] ?? null)) {
                $record[TranslationExpanderService::LOCALIZE_KEY] = BatchIndexRemapper::remap($record[TranslationExpanderService::LOCALIZE_KEY], $indexMap);
            }

            $parentIndex = count($expanded);
            $expanded[] = $record;

            foreach ($plan['children'] as $child) {
                $fields = BatchIndexRemapper::remap($child['fields'], $indexMap);
                $fields = array_merge($fields, $child['matchFields']);
                $fields[$child['parentField']] = '$ref:'.($parentIndex + $child['parentOffset']);

                $expanded[] = [
                    'table' => $child['table'],
                    'pid' => $child['pid'],
                    'fields' => $fields,
                ];
            }
        }

        return $expanded;
    }

    public function validate(mixed $record): void
    {
        $this->planRecord($record);
    }

    /**
     * @return array{record: mixed, children: list<Child>}
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
        $record['fields'] = $this->collectChildren($table, $fields, $record, 0, 1, $children);

        return ['record' => $record, 'children' => $children];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $record
     * @param list<Child>          $children
     *
     * @return array<string, mixed>
     */
    private function collectChildren(string $table, array $fields, array $record, int $parentOffset, int $depth, array &$children): array
    {
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

            if ($depth > self::MAX_DEPTH) {
                throw new InvalidParameterException(sprintf(
                    'Nested children are expanded %d levels deep, and `%s` of `%s` is one level more. '
                        .'Write the deeper records flat in the same call instead: each as its own entry with `%s` set to "$ref:N", '
                        .'N being the 0-based position of its parent in `records`.',
                    self::MAX_DEPTH,
                    $field,
                    $table,
                    $parentField,
                ));
            }

            if (isset($record['uid'])) {
                throw new InvalidParameterException(sprintf(
                    'Nested children in `%s` are only expanded when the parent is created. To attach one to the existing record %s:%d, write it as its own record in `records`: %s',
                    $field,
                    $table,
                    (int) $record['uid'],
                    $this->childRecordTemplate->render(
                        $table,
                        (int) $record['uid'],
                        (int) ($this->recordAccess->assertRecordEditAccess($table, (int) $record['uid'])['pid'] ?? 0),
                        $config,
                    ),
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

            $language = $this->languageOf($table, $fields);
            $childLanguageField = 0 !== $language ? $this->tcaCompatibilityService->getLanguageFieldName($childTable) : null;

            foreach ($value as $childFields) {
                $childFields = $this->unwrapChildFields($childFields);
                if (null !== $childLanguageField && !array_key_exists($childLanguageField, $childFields)) {
                    $childFields[$childLanguageField] = $language;
                }

                $index = count($children);
                $children[] = [
                    'table' => $childTable,
                    'pid' => (int) $record['pid'],
                    'parentField' => $parentField,
                    'parentOffset' => $parentOffset,
                    'matchFields' => $matchFields,
                    'fields' => [],
                ];
                $children[$index]['fields'] = $this->collectChildren(
                    $childTable,
                    $childFields,
                    ['pid' => $record['pid']],
                    $index + 1,
                    $depth + 1,
                    $children,
                );
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function languageOf(string $table, array $fields): int
    {
        $languageField = $this->tcaCompatibilityService->getLanguageFieldName($table);
        if (null === $languageField || !is_numeric($fields[$languageField] ?? null)) {
            return 0;
        }

        return (int) $fields[$languageField];
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
