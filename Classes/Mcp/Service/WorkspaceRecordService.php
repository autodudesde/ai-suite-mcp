<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

class WorkspaceRecordService
{
    public function __construct(
        private readonly WorkspaceContextService $workspaceContextService,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly LoggerInterface $logger,
    ) {}

    public function getWorkspaceId(): int
    {
        return $this->workspaceContextService->getWorkspaceId();
    }

    public function isActive(): bool
    {
        return $this->getWorkspaceId() > 0;
    }

    public function resolveVersionUid(string $table, int $uid): ?int
    {
        $workspaceId = $this->getWorkspaceId();
        if ($uid <= 0 || 0 === $workspaceId || !$this->tcaCompatibilityService->isWorkspaceAware($table)) {
            return null;
        }

        try {
            $version = BackendUtility::getWorkspaceVersionOfRecord($workspaceId, $table, $uid, 'uid');
        } catch (\Throwable $e) {
            $this->logger->warning('WorkspaceRecord: version lookup failed', [
                'table' => $table,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $versionUid = (int) ($version['uid'] ?? 0);

        return $versionUid > 0 && $versionUid !== $uid ? $versionUid : null;
    }

    // A relation stored against the live uid is never rendered in the draft.
    public function resolveWriteTarget(string $table, int $uid): int
    {
        return $this->resolveVersionUid($table, $uid) ?? $uid;
    }

    public function resolveLiveUid(string $table, int $uid): int
    {
        if ($uid <= 0 || !$this->tcaCompatibilityService->isWorkspaceAware($table)) {
            return $uid;
        }

        $row = BackendUtility::getRecord($table, $uid, 'uid,t3ver_oid');
        $liveUid = (int) ($row['t3ver_oid'] ?? 0);

        return $liveUid > 0 ? $liveUid : $uid;
    }

    /**
     * @return list<int>
     */
    public function relationTargets(string $table, int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }

        $targets = [$uid];

        $liveUid = $this->resolveLiveUid($table, $uid);
        if ($liveUid !== $uid) {
            $targets[] = $liveUid;
        }

        $versionUid = $this->resolveVersionUid($table, $liveUid);
        if (null !== $versionUid) {
            $targets[] = $versionUid;
        }

        return array_values(array_unique($targets));
    }

    /**
     * @param list<int> $uids
     *
     * @return list<array<string, mixed>>
     */
    public function overlay(string $table, array $uids): array
    {
        $rows = [];
        foreach ($uids as $uid) {
            // A version uid and its live uid are the same record; overlay resolves both to the same row.
            $row = BackendUtility::getRecordWSOL($table, $this->resolveLiveUid($table, $uid));
            if (!is_array($row)) {
                continue;
            }
            if ($this->isDeletePlaceholder($row)) {
                continue;
            }

            $rows[(int) ($row['uid'] ?? $uid)] = $row;
        }

        return array_values($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function overlayRows(string $table, array $rows): array
    {
        if (!$this->isActive()) {
            return $rows;
        }

        $overlaid = [];
        foreach ($rows as $row) {
            // workspaceOL() nulls the row out for records the workspace must not see.
            BackendUtility::workspaceOL($table, $row);
            if (!is_array($row)) {
                continue;
            }
            if ($this->isDeletePlaceholder($row)) {
                continue;
            }

            $overlaid[(int) ($row['uid'] ?? 0)] = $row;
        }

        return array_values($overlaid);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isDeletePlaceholder(array $row): bool
    {
        return $this->tcaCompatibilityService->isDeletePlaceholderState($row['t3ver_state'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function stateLabel(array $row): string
    {
        if (0 === (int) ($row['t3ver_wsid'] ?? 0)) {
            return '';
        }

        $state = $row['t3ver_state'] ?? null;
        if ($this->tcaCompatibilityService->isNewPlaceholderState($state)) {
            return 'new in workspace';
        }
        if ($this->tcaCompatibilityService->isDeletePlaceholderState($state)) {
            return 'deleted in workspace';
        }

        return 'workspace draft';
    }
}
