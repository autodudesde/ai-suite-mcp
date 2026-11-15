<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class RecordSelectionService
{
    public const MISSING_SCOPE = 'Provide uid, pid, rootPageId, or filters. rootPageId reads a whole subtree in one call, '
        .'and a filter of "" finds records whose field is empty, e.g. {"seo_title": ""}.';

    public function __construct(
        private readonly RecordAccessService $recordAccess,
        private readonly RecordRepository $recordRepository,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly WorkspaceRecordService $workspaceRecords,
    ) {}

    /**
     * @param array<mixed> $filters
     *
     * @return array{uids: list<int>, filters: array<string, mixed>, context: string, note: ?string}|string
     */
    public function select(string $table, ?int $pid, ?int $rootPageId, array $filters, int $limit, int $offset, bool $requireScope = true): array|string
    {
        if ($requireScope && null === $pid && null === $rootPageId && [] === $filters) {
            return self::MISSING_SCOPE;
        }
        if (null !== $pid && null !== $rootPageId) {
            return 'Give either pid (records on that one page) or rootPageId (that page and everything below it), not both.';
        }

        $this->recordAccess->validateTableReadAccess($table);

        $beUser = $GLOBALS['BE_USER'] ?? null;
        $beUser = $beUser instanceof BackendUserAuthentication ? $beUser : null;

        $allowedPids = null;
        $extraWhere = null;
        if (null !== $pid) {
            $this->recordAccess->assertPagePerm($pid, Permission::PAGE_SHOW);
            if ('pages' === $table && null !== $beUser && !$beUser->isAdmin()) {
                $extraWhere = $beUser->getPagePermsClause(Permission::PAGE_SHOW);
            }
        }
        $pageScopeColumn = 'pid';
        if (null !== $rootPageId) {
            $this->recordAccess->assertPagePerm($rootPageId, Permission::PAGE_SHOW);

            $allowedPids = $this->recordAccess->getReadablePageIds($rootPageId);
            if ([] === $allowedPids) {
                return sprintf(
                    'Page %d does not exist or is not readable for you, so the read has no scope. '
                    .'Use readPageTree to find a page you can reach.',
                    $rootPageId,
                );
            }

            // getSearchableWebmounts() subtracts hideRecords.pages; permission was asserted above.
            if (!in_array($rootPageId, $allowedPids, true)) {
                $allowedPids[] = $rootPageId;
            }

            $pageScopeColumn = 'pages' === $table ? 'uid' : 'pid';
        } elseif (null === $pid && null !== $beUser && !$beUser->isAdmin()) {
            $ignoresRootLevelRestriction = $this->tcaCompatibilityService->ignoresRootLevelRestriction($table);
            if (!$this->tcaCompatibilityService->canExistOnPages($table)) {
                if (!$ignoresRootLevelRestriction) {
                    return sprintf(
                        '%s records live at the root level of the installation, which only administrators can read.',
                        $table,
                    );
                }
            } else {
                $allowedPids = $this->recordAccess->getReadablePageIds(0, 99);
                if ([] === $allowedPids) {
                    return ['uids' => [], 'filters' => [], 'context' => '', 'note' => sprintf('No %s records accessible in your webmounts.', $table)];
                }
                if ('pages' === $table) {
                    $extraWhere = $beUser->getPagePermsClause(Permission::PAGE_SHOW);
                    $pageScopeColumn = 'uid';
                } elseif ($ignoresRootLevelRestriction && $this->tcaCompatibilityService->isRootLevel($table)) {
                    $allowedPids[] = 0;
                }
            }
        }

        $sanitizedFilters = [];
        foreach ($filters as $field => $value) {
            if (is_string($field) && $this->recordAccess->canAccessField($table, $field)) {
                $sanitizedFilters[$field] = $value;
            }
        }

        $uids = $this->recordRepository->findUidsByCriteria(
            $table,
            $pid,
            $sanitizedFilters,
            $allowedPids,
            $extraWhere,
            $this->tcaCompatibilityService->getSortField($table),
            $limit,
            $offset,
            $pageScopeColumn,
            'uid' === $pageScopeColumn ? $this->tcaCompatibilityService->getTranslationOriginPointerFieldName($table) : null,
        );

        $visible = [];
        foreach ($this->workspaceRecords->overlay($table, $uids) as $row) {
            $visible[] = (int) ($row['uid'] ?? 0);
        }

        return [
            'uids' => $visible,
            'filters' => $sanitizedFilters,
            'context' => match (true) {
                null !== $pid => sprintf('on page %d', $pid),
                null !== $rootPageId => sprintf('in the subtree of page %d (%d pages)', $rootPageId, count($allowedPids ?? [])),
                default => 'matching filters',
            },
            'note' => null,
        ];
    }
}
