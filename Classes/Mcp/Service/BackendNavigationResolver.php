<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\BackendRouteService;
use AutoDudes\AiSuiteMcp\Mcp\Enum\LinkStyle;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\SingletonInterface;

class BackendNavigationResolver implements SingletonInterface
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly Router $router,
        private readonly BackendRouteService $backendRouteService,
        private readonly BackendBaseUrlResolver $baseUrlResolver,
        private readonly BackendLinkBounceService $linkBounce,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public function buildUrl(string $action, array $params, LinkStyle $style = LinkStyle::Session): ?string
    {
        try {
            $url = match ($action) {
                'editRecord' => $this->editRecord($params, $style),
                'openPage' => $this->openPage($params, $style),
                'listRecords' => $this->listRecords($params, $style),
                'openModule' => $this->openModule($params, $style),
                'openAuditResult' => $this->auditRoute('ai_suite_audit_cached', $params, $style),
                'exportAudit' => $this->auditRoute('ai_suite_audit_export', $params, $style),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('MCP: backend navigation could not be resolved', [
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (null === $url || LinkStyle::Session === $style) {
            return $url;
        }

        $absolute = $this->baseUrlResolver->makeAbsolute($url);

        return null === $absolute ? null : $this->linkBounce->wrap($absolute);
    }

    public function buildModuleBaseUrl(string $identifier, LinkStyle $style = LinkStyle::Session): ?string
    {
        return $this->buildUrl('openModule', ['module' => $identifier], $style);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function editRecord(array $params, LinkStyle $style): ?string
    {
        $table = trim((string) ($params['table'] ?? ''));
        $uid = (int) ($params['uid'] ?? 0);
        if ('' === $table || $uid <= 0 || !$this->routeExists('record_edit')) {
            return null;
        }

        return $this->build('record_edit', ['edit' => [$table => [$uid => 'edit']]], $style);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function openPage(array $params, LinkStyle $style): ?string
    {
        $pageId = (int) ($params['pageId'] ?? 0);
        if ($pageId <= 0 || !$this->routeExists('web_layout')) {
            return null;
        }

        [$pageId, $languageUid] = $this->pageModuleScope($pageId);

        return $this->build(
            'web_layout',
            ['id' => $pageId] + $this->backendRouteService->getPageModuleLanguageParams($languageUid),
            $style,
        );
    }

    /**
     * @return array{int, int}
     */
    private function pageModuleScope(int $pageId): array
    {
        try {
            $row = BackendUtility::getRecordWSOL('pages', $pageId);
        } catch (\Throwable) {
            return [$pageId, 0];
        }
        if (!\is_array($row)) {
            return [$pageId, 0];
        }

        $languageUid = (int) ($row['sys_language_uid'] ?? 0);
        $parent = (int) ($row['l10n_parent'] ?? 0);

        return $languageUid > 0 && $parent > 0 ? [$parent, $languageUid] : [$pageId, 0];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function listRecords(array $params, LinkStyle $style): ?string
    {
        $pageId = (int) ($params['pageId'] ?? 0);
        $module = $this->backendRouteService->getRecordListModuleIdentifier();
        if ($pageId <= 0 || !$this->routeExists($module)) {
            return null;
        }

        return $this->build($module, ['id' => $pageId], $style);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function openModule(array $params, LinkStyle $style): ?string
    {
        $module = trim((string) ($params['module'] ?? ''));
        if ('' === $module || !$this->routeExists($module)) {
            return null;
        }
        $routeParams = [];
        $pageId = (int) ($params['pageId'] ?? 0);
        if ($pageId > 0) {
            $routeParams['id'] = $pageId;
        }

        return $this->build($module, $routeParams, $style);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function auditRoute(string $identifier, array $params, LinkStyle $style): ?string
    {
        $pageId = (int) ($params['pageId'] ?? 0);
        $auditType = trim((string) ($params['auditType'] ?? ''));
        if ($pageId <= 0 || '' === $auditType || !$this->routeExists($identifier)) {
            return null;
        }

        return $this->build($identifier, [
            'pageId' => $pageId,
            'auditType' => $auditType,
            'languageUid' => max(0, (int) ($params['languageUid'] ?? 0)),
        ], $style);
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    private function build(string $identifier, array $routeParams, LinkStyle $style): string
    {
        return (string) $this->uriBuilder->buildUriFromRoute($identifier, $routeParams, $style->referenceType());
    }

    private function routeExists(string $identifier): bool
    {
        return $this->router->hasRoute($identifier);
    }
}
