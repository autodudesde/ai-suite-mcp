<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Http;

use Mcp\Server\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * GET /aisuite-mcp/health.
 */
class HealthCheckEndpoint
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');

        $checks = [
            'mcp_enabled' => (bool) ($extConf['enableMcp'] ?? false),
            'php_version' => PHP_VERSION,
            'sdk_installed' => class_exists(Server::class),
            'workspace_available' => ExtensionManagementUtility::isLoaded('workspaces'),
            'write_mode' => (string) ($extConf['mcpWriteMode'] ?? 'auto'),
        ];

        $status = $checks['mcp_enabled'] && $checks['sdk_installed'] ? 'ready' : 'not_configured';

        return new JsonResponse([
            'status' => $status,
            'checks' => $checks,
        ]);
    }
}
