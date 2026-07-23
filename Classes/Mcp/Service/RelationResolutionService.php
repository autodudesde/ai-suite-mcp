<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Renders relational field values as human-readable "Title (uid)" instead of bare
 * UIDs, so the LLM does not have to guess what "12,15" means and the read stays
 * round-trippable (the UID is still present for a later write).
 *
 * Used in the raw / FormDataCompiler-fallback read paths of ReadRecordTool — the
 * normal path already resolves relations via FormDataCompiler's databaseRow.
 *
 * For clean single-table relations (select/inline with foreign_table, group with a
 * single allowed table) it builds explicit per-UID titles; for MM/category/multi-
 * allowed cases it falls back to the native, comma-separated
 * BackendUtility::getProcessedValue().
 */
class RelationResolutionService
{
    /**
     * Cap on explicit per-UID title lookups per field in list mode, to bound the
     * read fan-out. Single-record reads are unbudgeted.
     */
    private const MAX_LIST_LOOKUPS = 20;

    /**
     * Per-request cache: "{foreignTable}:{uid}" => rendered title.
     *
     * @var array<string, string>
     */
    private array $titleCache = [];

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly RecordAccessService $recordAccess,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $fullRow  the record the value belongs to (for getProcessedValue context)
     * @param bool                 $listMode whether this is a multi-record list read (enables the lookup budget)
     *
     * @return ?string the resolved value, or null when $field is not a relation (caller formats as before)
     */
    public function resolveFieldValue(string $table, string $field, mixed $rawValue, array $fullRow, bool $listMode): ?string
    {
        try {
            $config = $this->tcaCompatibilityService->getFieldConfiguration($table, $field);
        } catch (\Throwable) {
            return null;
        }

        if ([] === $config || !$this->tcaCompatibilityService->isRelationalFieldConfig($config)) {
            return null;
        }

        $foreignTable = $this->resolveForeignTable($config);
        $uids = null !== $foreignTable ? $this->extractUids($rawValue) : [];

        if (null !== $foreignTable && 'sys_file_reference' !== $foreignTable && [] !== $uids) {
            return $this->renderTitles($foreignTable, $uids, $listMode);
        }

        return $this->processedFallback($table, $field, $rawValue, $fullRow);
    }

    /**
     * Thin seam over the core helper — its signature differs across TYPO3 majors,
     * so keep the version-sensitive call in one overridable place (downmerge).
     *
     * @param array<string, mixed> $row
     */
    protected function getProcessedValue(string $table, string $field, string $value, array $row): string
    {
        return (string) BackendUtility::getProcessedValue(
            $table,
            $field,
            $value,
            0,
            false,
            false,
            (int) ($row['uid'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveForeignTable(array $config): ?string
    {
        $type = (string) ($config['type'] ?? '');

        // Only relations that store concrete UIDs in the parent column itself.
        // foreign_field-based inline stores a child *count*, not UIDs — skip it.
        if ('select' === $type && !empty($config['foreign_table']) && empty($config['MM'])) {
            return (string) $config['foreign_table'];
        }
        if ('inline' === $type && !empty($config['foreign_table']) && empty($config['foreign_field']) && empty($config['MM'])) {
            return (string) $config['foreign_table'];
        }
        if ('group' === $type) {
            $allowed = array_values(array_filter(array_map('trim', explode(',', (string) ($config['allowed'] ?? '')))));
            if (1 === \count($allowed) && '*' !== $allowed[0]) {
                return $allowed[0];
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function extractUids(mixed $rawValue): array
    {
        $parts = \is_array($rawValue) ? $rawValue : explode(',', (string) $rawValue);

        $uids = [];
        foreach ($parts as $part) {
            if (\is_array($part)) {
                $part = $part['uid'] ?? '';
            }
            // Trailing integer handles both "12" and "tablename_12" group syntax.
            if (preg_match('/(\d+)$/', (string) $part, $matches)) {
                $uid = (int) $matches[1];
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }
        }

        return $uids;
    }

    /**
     * @param list<int> $uids
     */
    private function renderTitles(string $foreignTable, array $uids, bool $listMode): string
    {
        $budget = $listMode ? self::MAX_LIST_LOOKUPS : \PHP_INT_MAX;

        $rendered = [];
        $shown = 0;
        foreach ($uids as $uid) {
            if ($shown >= $budget) {
                $rendered[] = sprintf('… (+%d more)', \count($uids) - $shown);

                break;
            }
            $rendered[] = $this->renderSingleTitle($foreignTable, $uid);
            ++$shown;
        }

        return implode(', ', $rendered);
    }

    private function renderSingleTitle(string $foreignTable, int $uid): string
    {
        $cacheKey = $foreignTable.':'.$uid;
        if (isset($this->titleCache[$cacheKey])) {
            return $this->titleCache[$cacheKey];
        }

        if (!$this->recordAccess->canReadRecordTitle($foreignTable, $uid)) {
            return $this->titleCache[$cacheKey] = sprintf('#%d (no access)', $uid);
        }

        $record = BackendUtility::getRecordWSOL($foreignTable, $uid);
        if (null === $record) {
            return $this->titleCache[$cacheKey] = sprintf('#%d [deleted]', $uid);
        }

        $title = trim((string) BackendUtility::getRecordTitle($foreignTable, $record));
        $rendered = '' !== $title
            ? sprintf('%s (%d)', $title, $uid)
            : sprintf('#%d', $uid);

        return $this->titleCache[$cacheKey] = $rendered;
    }

    /**
     * @param array<string, mixed> $fullRow
     */
    private function processedFallback(string $table, string $field, mixed $rawValue, array $fullRow): ?string
    {
        $scalar = \is_array($rawValue) ? implode(',', array_map('strval', $rawValue)) : (string) $rawValue;
        if ('' === trim($scalar)) {
            return null;
        }

        try {
            $processed = trim((string) $this->getProcessedValue($table, $field, $scalar, $fullRow));

            return '' !== $processed ? $processed : null;
        } catch (\Throwable $e) {
            $this->logger->warning('RelationResolutionService: getProcessedValue failed, leaving raw value', [
                'table' => $table,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
