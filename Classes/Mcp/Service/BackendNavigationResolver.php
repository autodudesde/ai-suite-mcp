<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\BackendRouteService;
use AutoDudes\AiSuiteMcp\Mcp\Enum\LinkStyle;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\SingletonInterface;

class BackendNavigationResolver implements SingletonInterface
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly Router $router,
        private readonly BackendRouteService $backendRouteService,
        private readonly BackendBaseUrlResolver $baseUrlResolver,
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

        // UriBuilder takes host and scheme from the request context, which stdio does not have.
        return $this->baseUrlResolver->makeAbsolute($url);
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

        return $this->build('web_layout', ['id' => $pageId], $style);
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
