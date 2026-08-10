<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\StatusReport;

use AutoDudes\AiSuiteMcp\Mcp\Service\McpSessionStoreService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class McpSecurityStatus implements StatusProviderInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly McpSessionStoreService $sessionStore,
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

        if ($isProduction && empty(trim((string) ($extConf['mcpAllowedOrigins'] ?? '')))) {
            $statuses[] = new Status(
                'MCP CORS Configuration',
                'No CORS origins configured',
                'MCP has no CORS origins configured. In production, this means no CORS headers are sent (same-origin only). '
                .'If browser-based MCP clients need access, configure allowed origins in Extension Settings → AI Suite → MCP.',
                ContextualFeedbackSeverity::INFO,
            );
        }

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

        $statuses[] = $this->sessionStoreStatus();

        return $statuses;
    }

    public function getLabel(): string
    {
        return 'AI Suite MCP Security';
    }

    private function sessionStoreStatus(): Status
    {
        $count = $this->sessionStore->countFiles();
        $exceeded = $count > McpSessionStoreService::WARN_THRESHOLD;

        return new Status(
            'MCP Session Store',
            sprintf('%d stored session file(s)', $count),
            $exceeded
                ? sprintf(
                    '%d session files are stored in %s. Stateless MCP clients leave one behind per request. '
                    .'Schedule ai-suite-mcp:cleanup — it removes session files older than %d seconds.',
                    $count,
                    $this->sessionStore->getDirectory(),
                    $this->sessionStore->getRetentionSeconds(),
                )
                : sprintf(
                    'Session files in %s are within the expected range. ai-suite-mcp:cleanup keeps them bounded.',
                    $this->sessionStore->getDirectory(),
                ),
            $exceeded ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::OK,
        );
    }
}
