<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

class WorkspaceComparisonService
{
    /**
     * @var list<string>
     */
    private const SKIP_FIELDS = [
        'uid', 'pid', 'tstamp', 'crdate', 'cruser_id', 'sorting',
        'l10n_diffsource', 'l10n_source', 'l18n_parent', 'l10n_state',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_timestamp', 't3ver_label', 't3_origuid',
    ];

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly RecordAccessService $recordAccess,
        private readonly OutputFormatterService $outputFormatter,
        private readonly RecordRepository $recordRepository,
        private readonly BackendUserService $backendUserService,
        private readonly LoggerInterface $logger,
    ) {}

    public function isWorkspacesLoaded(): bool
    {
        return ExtensionManagementUtility::isLoaded('workspaces');
    }

    public function getCurrentWorkspaceId(): int
    {
        return (int) ($this->backendUserService->getBackendUser()?->workspace ?? 0);
    }

    /**
     * @return array{status: string, liveUid: int, label: string, changes: array<string, array{old: string, new: string}>, newFields: array<string, string>}
     *
     * @throws \RuntimeException when the record does not exist
     */
    public function compareSingle(string $table, int $uid, int $currentWs): array
    {
        $row = $this->loadRaw($table, $uid);
        if (null === $row) {
            throw new \RuntimeException(sprintf('Record %s:%d not found.', $table, $uid));
        }

        $rowWsid = (int) ($row['t3ver_wsid'] ?? 0);
        $state = (int) ($row['t3ver_state'] ?? 0);

        // Case A: the caller passed a workspace-version uid (offline / new / delete record).
        if ($rowWsid === $currentWs && $rowWsid > 0) {
            if ($this->tcaCompatibilityService->isNewPlaceholderState($state)) {
                return $this->result('added', $uid, $this->recordLabel($table, $row), [], $this->presentFields($table, $row));
            }

            $liveUid = (int) ($row['t3ver_oid'] ?? 0);
            $liveRow = $liveUid > 0 ? $this->loadRaw($table, $liveUid) : null;

            if ($this->tcaCompatibilityService->isDeletePlaceholderState($state)) {
                return $this->result('removed', $liveUid, $this->recordLabel($table, $liveRow ?? $row));
            }

            $changes = null !== $liveRow ? $this->diffFields($table, $liveRow, $row) : [];

            return $this->result([] !== $changes ? 'changed' : 'unchanged', $liveUid > 0 ? $liveUid : $uid, $this->recordLabel($table, $liveRow ?? $row), $changes);
        }

        // Case B: the caller passed a live uid,  look up its workspace overlay (if any).
        $wsVersion = BackendUtility::getWorkspaceVersionOfRecord($currentWs, $table, $uid);
        if (!is_array($wsVersion)) {
            return $this->result('unchanged', $uid, $this->recordLabel($table, $row));
        }

        if ($this->tcaCompatibilityService->isDeletePlaceholderState((int) ($wsVersion['t3ver_state'] ?? 0))) {
            return $this->result('removed', $uid, $this->recordLabel($table, $row));
        }

        $changes = $this->diffFields($table, $row, $wsVersion);

        return $this->result([] !== $changes ? 'changed' : 'unchanged', $uid, $this->recordLabel($table, $row), $changes);
    }

    /**
     * @param array<string, null|scalar> $sanitizedFilters
     * @param null|list<int>             $allowedPids      null = no PID restriction (admin / page mode)
     *
     * @return array{changed: list<array{liveUid: int, label: string, changes: array<string, array{old: string, new: string}>}>, added: list<array{uid: int, label: string, fields: array<string, string>}>, removed: list<array{liveUid: int, label: string}>, unchangedCount: null|int, truncated: bool}
     */
    public function compareSet(string $table, ?int $pid, array $sanitizedFilters, ?array $allowedPids, int $currentWs, int $limit): array
    {
        $changed = [];
        $added = [];
        $removed = [];
        $truncated = false;

        $workspaceService = GeneralUtility::makeInstance(WorkspaceService::class);
        $result = $workspaceService->selectVersionsInWorkspace($currentWs, -99, $pid ?? -1, 0, 'tables_select', null);
        $entries = $result[$table] ?? [];

        foreach ($entries as $entry) {
            $offlineUid = (int) ($entry['uid'] ?? 0);
            $liveUid = (int) ($entry['t3ver_oid'] ?? 0);
            if ($offlineUid <= 0) {
                continue;
            }

            $offlineRow = $this->loadRaw($table, $offlineUid);
            if (null === $offlineRow) {
                continue;
            }

            $rowPid = (int) ($entry['livepid'] ?? $offlineRow['pid'] ?? 0);
            if (null !== $allowedPids && !in_array($rowPid, $allowedPids, true)) {
                continue;
            }

            $state = (int) ($offlineRow['t3ver_state'] ?? 0);

            if ($this->tcaCompatibilityService->isNewPlaceholderState($state)) {
                if (!$this->rowMatchesFilters($offlineRow, $sanitizedFilters)) {
                    continue;
                }
                $added[] = ['uid' => $offlineUid, 'label' => $this->recordLabel($table, $offlineRow), 'fields' => $this->presentFields($table, $offlineRow)];
            } elseif ($this->tcaCompatibilityService->isDeletePlaceholderState($state)) {
                $liveRow = $liveUid > 0 ? $this->loadRaw($table, $liveUid) : null;
                if (null === $liveRow || !$this->rowMatchesFilters($liveRow, $sanitizedFilters)) {
                    continue;
                }
                $removed[] = ['liveUid' => $liveUid, 'label' => $this->recordLabel($table, $liveRow)];
            } else {
                $liveRow = $liveUid > 0 ? $this->loadRaw($table, $liveUid) : null;
                if (null === $liveRow) {
                    continue;
                }
                if (!$this->rowMatchesFilters($offlineRow, $sanitizedFilters) && !$this->rowMatchesFilters($liveRow, $sanitizedFilters)) {
                    continue;
                }
                $changes = $this->diffFields($table, $liveRow, $offlineRow);
                if ([] === $changes) {
                    continue;
                }
                if (count($changed) >= $limit) {
                    $truncated = true;

                    continue;
                }
                $changed[] = ['liveUid' => $liveUid, 'label' => $this->recordLabel($table, $liveRow), 'changes' => $changes];
            }
        }

        $unchangedCount = null;
        if (null !== $pid) {
            $liveCount = $this->recordRepository->countLiveRecords($table, $pid);
            $unchangedCount = max(0, $liveCount - count($changed) - count($removed));
        }

        return [
            'changed' => $changed,
            'added' => $added,
            'removed' => $removed,
            'unchangedCount' => $unchangedCount,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array<string, mixed>  $changes
     * @param array<string, string> $newFields
     *
     * @return array{status: string, liveUid: int, label: string, changes: array<string, array{old: string, new: string}>, newFields: array<string, string>}
     */
    private function result(string $status, int $liveUid, string $label, array $changes = [], array $newFields = []): array
    {
        return ['status' => $status, 'liveUid' => $liveUid, 'label' => $label, 'changes' => $changes, 'newFields' => $newFields];
    }

    /**
     * @return null|array<string, mixed>
     */
    private function loadRaw(string $table, int $uid): ?array
    {
        return BackendUtility::getRecord($table, $uid);
    }

    /**
     * @param array<string, mixed> $live
     * @param array<string, mixed> $ws
     *
     * @return array<string, array{old: string, new: string}>
     */
    private function diffFields(string $table, array $live, array $ws): array
    {
        $changes = [];
        $skip = $this->skipFields($table);

        /** @var list<string> $fields */
        $fields = array_values(array_unique([...array_keys($live), ...array_keys($ws)]));
        foreach ($fields as $field) {
            if (in_array($field, $skip, true) || !$this->recordAccess->canAccessField($table, $field)) {
                continue;
            }
            $old = $this->outputFormatter->scalarize($live[$field] ?? '');
            $new = $this->outputFormatter->scalarize($ws[$field] ?? '');
            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function presentFields(string $table, array $row): array
    {
        $out = [];
        $skip = $this->skipFields($table);
        foreach ($row as $field => $value) {
            if (in_array($field, $skip, true) || !$this->recordAccess->canAccessField($table, (string) $field)) {
                continue;
            }
            $scalar = $this->outputFormatter->scalarize($value);
            if ('' !== $scalar) {
                $out[(string) $field] = $scalar;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function skipFields(string $table): array
    {
        $skip = self::SKIP_FIELDS;

        try {
            $deleteField = $this->tcaCompatibilityService->getDeleteField($table);
            if ('' !== $deleteField) {
                $skip[] = $deleteField;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('WorkspaceComparisonService: delete-field lookup failed, comparing without it', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
        }

        return $skip;
    }

    /**
     * @param array<string, mixed>       $row
     * @param array<string, null|scalar> $filters
     */
    private function rowMatchesFilters(array $row, array $filters): bool
    {
        foreach ($filters as $field => $value) {
            $rowValue = $this->outputFormatter->scalarize($row[$field] ?? '');
            if (null === $value || '' === (string) $value) {
                if ('' !== $rowValue) {
                    return false;
                }
            } elseif ($rowValue !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param null|array<string, mixed> $row
     */
    private function recordLabel(string $table, ?array $row): string
    {
        if (null === $row) {
            return '';
        }

        try {
            $labelField = $this->tcaCompatibilityService->getLabelField($table);
        } catch (\Throwable $e) {
            $labelField = 'uid';
        }

        $label = $this->outputFormatter->scalarize($row[$labelField] ?? '');

        return '' !== $label ? $label : sprintf('uid %s', (string) ($row['uid'] ?? '?'));
    }
}
