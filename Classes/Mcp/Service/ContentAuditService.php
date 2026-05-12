<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
use AutoDudes\AiSuite\Domain\Repository\SysFileReferenceRepository;
use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\SiteService;
use AutoDudes\AiSuiteMcp\Mcp\AbstractTool;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;

class ContentAuditService
{
    /**
     * Counter for sys_file_reference / image-issue rows that point to pages outside the
     * BE user's webmount and were therefore excluded from the audit report. Reset per audit() call.
     */
    private int $excludedDueToPermissions = 0;

    public function __construct(
        private readonly PagesRepository $pagesRepository,
        private readonly SysFileReferenceRepository $sysFileReferenceRepository,
        private readonly SiteService $siteService,
        private readonly BackendUserService $backendUserService,
        private readonly Context $context,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $checks
     * @param list<string> $targetLanguages
     *
     * @return array<string, mixed>
     */
    public function audit(int $pageId, int $depth, array $checks, array $targetLanguages, int $limit = 100): array
    {
        $this->excludedDueToPermissions = 0;
        $pageIds = $this->pagesRepository->getSubtreePageIdsWorkspaceAware($pageId, $depth, $this->currentWorkspaceId());

        // Webmount whitelist: drop pages outside the user's accessible tree.
        $beUser = $this->backendUserService->getBackendUser();
        if (null !== $beUser && !$beUser->isAdmin() && [] !== $pageIds) {
            $allowedPids = $this->backendUserService->getSearchableWebmounts($pageId, max(1, $depth));
            if (count($allowedPids) > AbstractTool::MAX_FILTERABLE_PAGES) {
                throw new \RuntimeException('Audit scope too large — provide a more specific pageId or reduce depth.');
            }
            $allowed = array_flip($allowedPids);
            $filtered = array_values(array_filter($pageIds, static fn (int $id): bool => isset($allowed[$id])));
            $this->excludedDueToPermissions += count($pageIds) - count($filtered);
            $pageIds = $filtered;
        }

        $issues = [];

        if (in_array('seo', $checks, true)) {
            $issues = array_merge($issues, $this->checkSeo($pageIds));
        }
        if (in_array('translations', $checks, true)) {
            $languageUids = $this->resolveLanguageUids($targetLanguages, $pageId);
            $issues = array_merge($issues, $this->checkTranslations($pageIds, $languageUids));
        }
        if (in_array('accessibility', $checks, true)) {
            $issues = array_merge($issues, $this->checkAccessibility($pageIds));
        }
        if (in_array('images', $checks, true)) {
            $issues = array_merge($issues, $this->checkImages($pageIds));
        }

        // Apply limit
        $totalIssues = count($issues);
        $issues = array_slice($issues, 0, $limit);

        $bySeverity = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($issues as $issue) {
            $bySeverity[$issue['severity']] = ($bySeverity[$issue['severity']] ?? 0) + 1;
        }

        return [
            'summary' => [
                'pagesChecked' => count($pageIds),
                'totalIssues' => $totalIssues,
                'bySeverity' => $bySeverity,
                'limited' => $totalIssues > $limit,
                'excludedDueToPermissions' => $this->excludedDueToPermissions,
            ],
            'issues' => $issues,
        ];
    }

    private function currentWorkspaceId(): int
    {
        try {
            return (int) $this->context->getPropertyFromAspect('workspace', 'id', 0);
        } catch (\Throwable $e) {
            $this->logger->warning('ContentAuditService: could not resolve workspace aspect, falling back to live workspace (0)', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @param list<int> $pageIds
     *
     * @return list<array<string, mixed>>
     */
    private function checkSeo(array $pageIds): array
    {
        $issues = [];
        $pages = $this->pagesRepository->findSeoFields($pageIds, $this->currentWorkspaceId());

        foreach ($pages as $page) {
            $uid = (int) $page['uid'];
            $title = (string) $page['title'];

            if ('' === trim((string) $page['seo_title'])) {
                $issues[] = [
                    'severity' => 'warning', 'category' => 'seo',
                    'page' => ['uid' => $uid, 'title' => $title],
                    'field' => 'pages.seo_title',
                    'message' => 'SEO title is empty',
                    'suggestion' => 'Use generateMetadata tool to create SEO title',
                    'suggestedTool' => ['name' => 'generateMetadata', 'requiredScope' => 'mcp:generate', 'estimatedCredits' => 1],
                ];
            } elseif (mb_strlen((string) $page['seo_title']) > 60) {
                $issues[] = [
                    'severity' => 'warning', 'category' => 'seo',
                    'page' => ['uid' => $uid, 'title' => $title],
                    'field' => 'pages.seo_title',
                    'message' => sprintf('SEO title is too long (%d characters, recommended max 60)', mb_strlen((string) $page['seo_title'])),
                    'suggestion' => 'Use generateMetadata tool to optimize SEO title length',
                    'suggestedTool' => ['name' => 'generateMetadata', 'requiredScope' => 'mcp:generate', 'estimatedCredits' => 1],
                ];
            }

            if ('' === trim((string) $page['description'])) {
                $issues[] = [
                    'severity' => 'warning', 'category' => 'seo',
                    'page' => ['uid' => $uid, 'title' => $title],
                    'field' => 'pages.description',
                    'message' => 'Meta description is empty',
                    'suggestion' => 'Use generateMetadata tool to create meta description',
                    'suggestedTool' => ['name' => 'generateMetadata', 'requiredScope' => 'mcp:generate', 'estimatedCredits' => 1],
                ];
            }
        }

        return $issues;
    }

    /**
     * @param list<int> $languageUids
     * @param list<int> $pageIds
     *
     * @return list<array<string, mixed>>
     */
    private function checkTranslations(array $pageIds, array $languageUids): array
    {
        $issues = [];

        foreach ($pageIds as $pageId) {
            $page = BackendUtility::getRecordWSOL('pages', $pageId);
            if (null === $page) {
                continue;
            }

            foreach ($languageUids as $langUid) {
                if ($this->pagesRepository->checkPageTranslationExists($pageId, $langUid)) {
                    continue;
                }

                $issues[] = [
                    'severity' => 'warning', 'category' => 'translations',
                    'page' => ['uid' => $pageId, 'title' => (string) $page['title']],
                    'message' => sprintf('Missing translation for language UID %d', $langUid),
                    'suggestion' => 'Use translatePage tool to translate this page',
                    'suggestedTool' => ['name' => 'translatePage', 'requiredScope' => 'mcp:translate', 'estimatedCredits' => 3],
                ];
            }
        }

        return $issues;
    }

    /**
     * @param list<int> $pageIds
     *
     * @return list<array<string, mixed>>
     */
    private function checkAccessibility(array $pageIds): array
    {
        $refs = $this->sysFileReferenceRepository->findImagesWithoutAlt($pageIds, $this->currentWorkspaceId());

        // Group by page
        $byPage = [];
        foreach ($refs as $ref) {
            $pid = (int) $ref['pid'];
            $byPage[$pid][] = $ref;
        }

        $issues = [];
        foreach ($byPage as $pid => $pageRefs) {
            $page = BackendUtility::getRecordWSOL('pages', $pid);
            $issues[] = [
                'severity' => 'error', 'category' => 'accessibility',
                'page' => ['uid' => $pid, 'title' => (string) ($page['title'] ?? '')],
                'field' => 'sys_file_reference.alternative',
                'message' => sprintf('%d image(s) without alt text', count($pageRefs)),
                'suggestion' => 'Use generateFileMetadata tool to auto-generate alt texts',
                'suggestedTool' => ['name' => 'generateFileMetadata', 'requiredScope' => 'mcp:generate', 'estimatedCredits' => count($pageRefs)],
                'affectedRecords' => array_map(fn ($r) => ['table' => 'sys_file_reference', 'uid' => (int) $r['uid']], $pageRefs),
            ];
        }

        return $issues;
    }

    /**
     * @param list<int> $pageIds
     *
     * @return list<array<string, mixed>>
     */
    private function checkImages(array $pageIds): array
    {
        $refs = $this->sysFileReferenceRepository->findImagesWithMetadata($pageIds, $this->currentWorkspaceId());

        $issues = [];
        $checked = [];

        foreach ($refs as $ref) {
            $fileUid = (int) $ref['uid_local'];
            if (isset($checked[$fileUid])) {
                continue;
            }
            $checked[$fileUid] = true;

            $missingFields = [];
            if ('' === trim((string) ($ref['alternative'] ?? ''))) {
                $missingFields[] = 'alternative (alt text)';
            }
            if ('' === trim((string) ($ref['title'] ?? ''))) {
                $missingFields[] = 'title';
            }

            if (!empty($missingFields)) {
                $page = BackendUtility::getRecordWSOL('pages', (int) $ref['pid']);
                $issues[] = [
                    'severity' => 'warning', 'category' => 'images',
                    'page' => ['uid' => (int) $ref['pid'], 'title' => (string) ($page['title'] ?? '')],
                    'fileUid' => $fileUid,
                    'field' => 'sys_file_metadata',
                    'message' => sprintf('File "%s" (UID %d) missing: %s', $ref['name'], $fileUid, implode(', ', $missingFields)),
                    'suggestion' => 'Use generateFileMetadata tool to generate missing metadata',
                    'suggestedTool' => ['name' => 'generateFileMetadata', 'requiredScope' => 'mcp:generate', 'estimatedCredits' => 1],
                ];
            }
        }

        return $issues;
    }

    /**
     * @param list<string> $targetLanguages
     *
     * @return list<int>
     */
    private function resolveLanguageUids(array $targetLanguages, int $pageId): array
    {
        if ([] !== $targetLanguages) {
            return $this->siteService->getLanguageUidsByIsocodes($targetLanguages, $pageId);
        }

        return $this->siteService->getNonDefaultLanguageUids($pageId);
    }
}
