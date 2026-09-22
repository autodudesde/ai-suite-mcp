<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\SysFileReferenceRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;

class ElementLabelService
{
    private const MAX_LABEL_LENGTH = 80;
    private const TEXT_FIELD_TYPES = ['input', 'text'];

    /** @var array<string, null|array<string, mixed>> */
    private array $storedRows = [];

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly RecordRepository $recordRepository,
        private readonly SysFileReferenceRepository $sysFileReferenceRepository,
    ) {}

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    public function resolveForRows(string $table, array $rows): array
    {
        $uids = [];
        $typeByUid = [];
        $typeField = $this->tcaCompatibilityService->getSubSchemaDivisorFieldName($table);
        foreach ($rows as $row) {
            $uid = (int) ($row['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $uids[] = $uid;
            $typeByUid[$uid] = null !== $typeField ? (string) ($row[$typeField] ?? '') : '';
        }
        if ([] === $uids) {
            return [];
        }

        $this->storedRows = [];
        $labels = $this->fromTcaTitle($table, $rows);
        $open = array_values(array_diff($uids, array_keys($labels)));

        foreach ([
            fn (array $o): array => $this->fromOwnTextFields($table, $o, $typeByUid),
            fn (array $o): array => $this->fromCollectionChildren($table, $o, $typeByUid),
            fn (array $o): array => $this->fromListRelations($table, $o, $typeByUid),
            fn (array $o): array => $this->fromMediaReference($table, $o),
        ] as $stage) {
            if ([] === $open) {
                break;
            }
            foreach ($stage($open) as $uid => $label) {
                $labels[$uid] = $label;
            }
            $open = array_values(array_diff($open, array_keys($labels)));
        }

        return $labels;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private function fromTcaTitle(string $table, array $rows): array
    {
        $candidates = $this->existingColumns($table, array_merge(
            [$this->tcaCompatibilityService->getLabelField($table)],
            $this->tcaCompatibilityService->getLabelAltFields($table),
        ));
        if ([] === $candidates) {
            return [];
        }

        $labels = [];
        foreach ($rows as $row) {
            $label = $this->firstNonEmpty($row, $candidates);
            if ('' !== $label) {
                $labels[(int) $row['uid']] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param list<int>          $uids
     * @param array<int, string> $typeByUid
     *
     * @return array<int, string>
     */
    private function fromOwnTextFields(string $table, array $uids, array $typeByUid): array
    {
        $labels = [];
        foreach ($this->groupByType($uids, $typeByUid) as $type => $typeUids) {
            $columns = $this->existingColumns($table, $this->textFieldsOfType($table, (string) $type));
            if ([] === $columns) {
                continue;
            }
            foreach ($typeUids as $uid) {
                $row = $this->storedRow($table, $uid);
                if ([] === $row) {
                    continue;
                }
                $label = $this->firstNonEmpty($row, $columns);
                if ('' !== $label) {
                    $labels[$uid] = $label;
                }
            }
        }

        return $labels;
    }

    /**
     * @param list<int>          $uids
     * @param array<int, string> $typeByUid
     *
     * @return array<int, string>
     */
    private function fromCollectionChildren(string $table, array $uids, array $typeByUid): array
    {
        $labels = [];
        foreach ($this->groupByType($uids, $typeByUid) as $type => $typeUids) {
            foreach ($this->inlineRelationsOfType($table, (string) $type) as $relation) {
                $childLabelField = $this->tcaCompatibilityService->getLabelField($relation['foreign_table']);
                if ('uid' === $childLabelField) {
                    continue;
                }
                $found = $this->recordRepository->findFirstChildLabelPerParent(
                    $relation['foreign_table'],
                    $relation['foreign_field'],
                    $childLabelField,
                    $typeUids,
                    $this->tcaCompatibilityService->getSortField($relation['foreign_table']),
                );
                foreach ($found as $uid => $child) {
                    if (isset($labels[$uid]) || '' === $child['label']) {
                        continue;
                    }
                    $childLabel = $this->shorten(strip_tags($child['label']));
                    if ('' === $childLabel) {
                        continue;
                    }
                    $labels[$uid] = $child['total'] > 1
                        ? sprintf('%s +%d more', $childLabel, $child['total'] - 1)
                        : $childLabel;
                }
            }
        }

        return $labels;
    }

    /**
     * @param list<int>          $uids
     * @param array<int, string> $typeByUid
     *
     * @return array<int, string>
     */
    private function fromListRelations(string $table, array $uids, array $typeByUid): array
    {
        $labels = [];
        foreach ($this->groupByType($uids, $typeByUid) as $type => $typeUids) {
            foreach ($this->listRelationsOfType($table, (string) $type) as $field => $foreignTable) {
                $targetLabelField = $this->tcaCompatibilityService->getLabelField($foreignTable);
                if ('uid' === $targetLabelField) {
                    continue;
                }

                $firstTargetByUid = [];
                $targetUids = [];
                foreach ($typeUids as $uid) {
                    if (isset($labels[$uid])) {
                        continue;
                    }
                    $value = (string) ($this->storedRow($table, $uid)[$field] ?? '');
                    $items = array_values(array_filter(array_map('intval', explode(',', $value))));
                    if ([] === $items) {
                        continue;
                    }
                    $firstTargetByUid[$uid] = ['target' => $items[0], 'total' => \count($items)];
                    $targetUids[] = $items[0];
                }
                if ([] === $targetUids) {
                    continue;
                }

                $targetLabels = $this->recordRepository->findLabelsByUids($foreignTable, $targetLabelField, $targetUids);
                foreach ($firstTargetByUid as $uid => $entry) {
                    $label = $this->shorten(strip_tags($targetLabels[$entry['target']] ?? ''));
                    if ('' === $label) {
                        continue;
                    }
                    $labels[$uid] = $entry['total'] > 1
                        ? sprintf('%s +%d more', $label, $entry['total'] - 1)
                        : $label;
                }
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function listRelationsOfType(string $table, string $type): array
    {
        $showitem = '' !== $type ? $this->tcaCompatibilityService->getShowitem($table, $type) : '';

        $relations = [];
        foreach ($this->tcaCompatibilityService->getColumnConfigs($table) as $field => $config) {
            $configType = (string) ($config['type'] ?? '');
            $foreignTable = 'group' === $configType
                ? (string) ($config['allowed'] ?? '')
                : (string) ($config['foreign_table'] ?? '');

            if (!\in_array($configType, ['group', 'select'], true)
                || '' === $foreignTable
                || str_contains($foreignTable, ',')
                || '*' === $foreignTable
                || \in_array($foreignTable, ['pages', 'tt_content'], true)
            ) {
                continue;
            }
            if ('' !== $showitem && !str_contains($showitem, (string) $field)) {
                continue;
            }
            $relations[(string) $field] = $foreignTable;
        }

        return $relations;
    }

    /**
     * @param list<int> $uids
     *
     * @return array<int, string>
     */
    private function fromMediaReference(string $table, array $uids): array
    {
        $labels = [];
        foreach ($this->sysFileReferenceRepository->findFirstMediaLabelPerParent($table, $uids) as $uid => $label) {
            $labels[$uid] = $this->shorten($label);
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function textFieldsOfType(string $table, string $type): array
    {
        $labelFields = array_merge(
            [$this->tcaCompatibilityService->getLabelField($table)],
            $this->tcaCompatibilityService->getLabelAltFields($table),
        );
        $ignored = array_merge($labelFields, $this->tcaCompatibilityService->getHousekeepingFields());
        $showitem = '' !== $type ? $this->tcaCompatibilityService->getShowitem($table, $type) : '';

        $fields = [];
        foreach ($this->tcaCompatibilityService->getColumnConfigs($table) as $field => $config) {
            if (\in_array($field, $ignored, true)
                || !\in_array((string) ($config['type'] ?? ''), self::TEXT_FIELD_TYPES, true)
                || isset($config['items'], $config['renderType'])
                || '' !== (string) ($config['renderType'] ?? '')
            ) {
                continue;
            }
            if ('' !== $showitem && !str_contains($showitem, $field)) {
                continue;
            }
            $fields[] = (string) $field;
        }

        return $fields;
    }

    /**
     * @return list<array{foreign_table: string, foreign_field: string}>
     */
    private function inlineRelationsOfType(string $table, string $type): array
    {
        $showitem = '' !== $type ? $this->tcaCompatibilityService->getShowitem($table, $type) : '';

        $relations = [];
        foreach ($this->tcaCompatibilityService->getColumnConfigs($table) as $field => $config) {
            $foreignTable = (string) ($config['foreign_table'] ?? '');
            $foreignField = (string) ($config['foreign_field'] ?? '');
            if ('inline' !== (string) ($config['type'] ?? '')
                || '' === $foreignTable
                || '' === $foreignField
                || 'sys_file_reference' === $foreignTable
            ) {
                continue;
            }
            if ('' !== $showitem && !str_contains($showitem, (string) $field)) {
                continue;
            }
            $relations[] = ['foreign_table' => $foreignTable, 'foreign_field' => $foreignField];
        }

        return $relations;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(string $table, int $uid): array
    {
        $key = $table.':'.$uid;
        if (!\array_key_exists($key, $this->storedRows)) {
            $row = BackendUtility::getRecordWSOL($table, $uid);
            $this->storedRows[$key] = \is_array($row) ? $row : null;
        }

        return $this->storedRows[$key] ?? [];
    }

    /**
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private function existingColumns(string $table, array $candidates): array
    {
        $columns = [];
        foreach (array_unique($candidates) as $candidate) {
            if ('' !== $candidate && 'uid' !== $candidate && $this->tcaCompatibilityService->hasField($table, $candidate)) {
                $columns[] = $candidate;
            }
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $fields
     */
    private function firstNonEmpty(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim(strip_tags((string) ($row[$field] ?? '')));
            if ('' !== $value) {
                return $this->shorten($value);
            }
        }

        return '';
    }

    /**
     * @param list<int>          $uids
     * @param array<int, string> $typeByUid
     *
     * @return array<string, list<int>>
     */
    private function groupByType(array $uids, array $typeByUid): array
    {
        $grouped = [];
        foreach ($uids as $uid) {
            $grouped[$typeByUid[$uid] ?? ''][] = $uid;
        }

        return $grouped;
    }

    private function shorten(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($value) > self::MAX_LABEL_LENGTH
            ? mb_substr($value, 0, self::MAX_LABEL_LENGTH).'…'
            : $value;
    }
}
