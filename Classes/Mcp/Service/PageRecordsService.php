<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;

class PageRecordsService
{
    private const RECORDS_PER_TABLE_CAP = 20;

    private const LISTED_ELSEWHERE = ['pages', 'tt_content'];

    public function __construct(
        private readonly SearchableTablesService $searchableTables,
        private readonly RecordAccessService $recordAccess,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly RecordRepository $recordRepository,
        private readonly ElementLabelService $elementLabels,
    ) {}

    /**
     * @return array<string, array{records: list<array{uid: int, label: string}>, total: int}>
     */
    public function findOnPage(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        $found = [];
        foreach ($this->searchableTables->getSearchableTables(self::LISTED_ELSEWHERE) as $table) {
            if (!$this->recordAccess->hasTableReadAccess($table)) {
                continue;
            }
            $columns = $this->labelColumns($table);
            $rows = $this->recordRepository->findRecordsOnPage($table, $pageId, $columns, self::RECORDS_PER_TABLE_CAP);
            if ([] === $rows) {
                continue;
            }

            $labels = $this->elementLabels->resolveForRows($table, $rows);

            $records = [];
            foreach ($rows as $row) {
                $uid = (int) $row['uid'];
                $records[] = ['uid' => $uid, 'label' => $labels[$uid] ?? ''];
            }
            $found[$table] = [
                'records' => $records,
                'total' => $this->recordRepository->countRecordsOnPage($table, $pageId),
            ];
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function labelColumns(string $table): array
    {
        $candidates = array_merge(
            [$this->tcaCompatibilityService->getLabelField($table)],
            $this->tcaCompatibilityService->getLabelAltFields($table),
        );

        $columns = [];
        foreach (array_unique($candidates) as $candidate) {
            if ('' !== $candidate && 'uid' !== $candidate && $this->tcaCompatibilityService->hasField($table, $candidate)) {
                $columns[] = $candidate;
            }
        }

        return $columns;
    }
}
