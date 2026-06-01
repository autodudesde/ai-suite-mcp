<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\StatusReport;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class McpSecurityStatus implements StatusProviderInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @return list<Status>
     */
    public function getStatus(): array
    {
        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');

        if (!((bool) ($extConf['enableMcp'] ?? false))) {
            return [];
        }

        $statuses = [];
        $isProduction = Environment::getContext()->isProduction();

        // HTTP allowed in production
        if ((bool) ($extConf['mcpAllowHttp'] ?? false)) {
            $statuses[] = new Status(
                'MCP HTTP Security',
                'HTTP allowed',
                'MCP is configured to accept unencrypted HTTP connections (mcpAllowHttp=1). '
                .'This exposes Bearer tokens to network interception. '
                .'Disable this setting in production: Extension Settings → AI Suite → MCP → Allow HTTP.',
                ContextualFeedbackSeverity::WARNING,
            );
        }

        // Empty CORS allowlist in production
        if ($isProduction && empty(trim((string) ($extConf['mcpAllowedOrigins'] ?? '')))) {
            $statuses[] = new Status(
                'MCP CORS Configuration',
                'No CORS origins configured',
                'MCP has no CORS origins configured. In production, this means no CORS headers are sent (same-origin only). '
                .'If browser-based MCP clients need access, configure allowed origins in Extension Settings → AI Suite → MCP.',
                ContextualFeedbackSeverity::INFO,
            );
        }

        // Empty redirect URI allowlist in production
        if ($isProduction && empty(trim((string) ($extConf['mcpAllowedRedirectUris'] ?? '')))) {
            $statuses[] = new Status(
                'MCP Redirect URI Security',
                'No external redirect URIs configured',
                'Only localhost redirect URIs are allowed for OAuth. To allow external MCP clients, '
                .'configure allowed redirect URIs in Extension Settings → AI Suite → MCP.',
                ContextualFeedbackSeverity::INFO,
            );
        }

        if (empty($statuses)) {
            $statuses[] = new Status(
                'MCP Security',
                'Configuration looks good',
                'MCP security settings are properly configured.',
                ContextualFeedbackSeverity::OK,
            );
        }

        return $statuses;
    }

    public function getLabel(): string
    {
        return 'AI Suite MCP Security';
    }
}
