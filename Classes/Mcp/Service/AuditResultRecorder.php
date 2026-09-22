<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Domain\Repository\AuditResultRepository;
use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\MetadataService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * @phpstan-type StoredAudit array{pageId: int, languageUid: int, auditType: string, viewable: bool}
 */
class AuditResultRecorder
{
    public const AUDIT_MODULE = 'web_aisuite';

    public function __construct(
        private readonly SiteMatcher $siteMatcher,
        private readonly AuditResultRepository $auditResults,
        private readonly BackendUserService $backendUserService,
        private readonly MetadataService $metadataService,
        private readonly LoggerInterface $logger,
    ) {}

    public function urlForPage(int $pageId, ?int $languageUid = null): ?string
    {
        try {
            $url = $this->metadataService->getPreviewUrl($pageId, [], $languageUid);
        } catch (\Throwable $e) {
            $this->logger->warning('AuditResultRecorder: no public URL for page', [
                'pageId' => $pageId,
                'languageUid' => $languageUid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return '' !== $url ? $url : null;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return null|StoredAudit
     */
    public function record(string $auditType, string $url, string $keyword, array $body): ?array
    {
        $audit = $body['audit'] ?? null;
        if (\is_array($audit) && false === ($audit['reachable'] ?? true)) {
            return null;
        }

        $page = $this->resolvePage($url);
        if (null === $page) {
            return null;
        }

        try {
            $this->auditResults->store($page['pageId'], $auditType, $keyword, ['url' => $url] + $body, null, $page['languageUid']);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP: audit result could not be stored', [
                'auditType' => $auditType,
                'pageId' => $page['pageId'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $backendUser = $this->backendUserService->getBackendUser();

        return $page + [
            'auditType' => $auditType,
            'viewable' => null !== $backendUser && $backendUser->check('modules', self::AUDIT_MODULE),
        ];
    }

    /**
     * @return null|array{pageId: int, languageUid: int}
     */
    private function resolvePage(string $url): ?array
    {
        try {
            $request = new ServerRequest($url, 'GET');
            $siteResult = $this->siteMatcher->matchRequest($request);
            if (!$siteResult instanceof SiteRouteResult) {
                return null;
            }
            $site = $siteResult->getSite();
            $language = $siteResult->getLanguage();
            if (!$site instanceof Site || null === $language) {
                return null;
            }
            $pageArguments = $site->getRouter()->matchRequest(
                $request->withAttribute('site', $site)->withAttribute('language', $language)->withAttribute('routing', $siteResult),
                $siteResult,
            );
        } catch (\Throwable) {
            return null;
        }

        if (!$pageArguments instanceof PageArguments) {
            return null;
        }
        $pageId = $pageArguments->getPageId();
        if ($pageId <= 0 || !$this->canReadPage($pageId)) {
            return null;
        }

        return ['pageId' => $pageId, 'languageUid' => $language->getLanguageId()];
    }

    private function canReadPage(int $pageId): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (null === $backendUser) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $page = BackendUtility::getRecord('pages', $pageId);

        return null !== $page && $backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }
}
