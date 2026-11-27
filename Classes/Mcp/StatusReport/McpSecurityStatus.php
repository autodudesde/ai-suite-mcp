<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\StatusReport;

use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Mcp\Command\McpCleanupCommand;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpSessionStoreService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class McpSecurityStatus implements StatusProviderInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly McpSessionStoreService $sessionStore,
        private readonly LocalizationService $localizationService,
        private readonly Registry $registry,
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
            $statuses[] = $this->status('http', ContextualFeedbackSeverity::WARNING);
        }

        if ($isProduction && empty(trim((string) ($extConf['mcpAllowedOrigins'] ?? '')))) {
            $statuses[] = $this->status('cors', ContextualFeedbackSeverity::INFO);
        }

        if ($isProduction && empty(trim((string) ($extConf['mcpAllowedRedirectUris'] ?? '')))) {
            $statuses[] = $this->status('redirect', ContextualFeedbackSeverity::INFO);
        }

        if ($isProduction && (bool) ($extConf['mcpLogVerbose'] ?? false)) {
            $statuses[] = $this->status('verboseLog', ContextualFeedbackSeverity::WARNING);
        }

        if ('live' === (string) ($extConf['mcpWriteMode'] ?? 'workspace')) {
            $statuses[] = $this->status('writeLive', ContextualFeedbackSeverity::WARNING);
        }

        if (isset($extConf['mcpRateLimitRequests']) && (int) $extConf['mcpRateLimitRequests'] <= 0) {
            $statuses[] = $this->status('rateLimitOff', ContextualFeedbackSeverity::WARNING);
        }

        if ((bool) ($extConf['mcpAllowRawHtmlWrite'] ?? false)) {
            $statuses[] = $this->status('rawHtml', ContextualFeedbackSeverity::WARNING);
        }

        if (empty($statuses)) {
            $statuses[] = $this->status('ok', ContextualFeedbackSeverity::OK);
        }

        $statuses[] = $this->sessionStoreStatus();
        $statuses[] = $this->cleanupStatus();

        return $statuses;
    }

    public function getLabel(): string
    {
        return 'LLL:EXT:ai_suite_mcp/Resources/Private/Language/locallang_mcp.xlf:aiSuite.mcp.status.security.label';
    }

    private function status(string $key, ContextualFeedbackSeverity $severity): Status
    {
        return new Status(
            $this->localizationService->translate('mcp:status.'.$key.'.title'),
            $this->localizationService->translate('mcp:status.'.$key.'.value'),
            $this->localizationService->translate('mcp:status.'.$key.'.message'),
            $severity,
        );
    }

    private function sessionStoreStatus(): Status
    {
        $count = $this->sessionStore->countFiles();
        $exceeded = $count > McpSessionStoreService::WARN_THRESHOLD;

        return new Status(
            $this->localizationService->translate('mcp:status.sessions.title'),
            $this->localizationService->translate('mcp:status.sessions.value', [$count]),
            $exceeded
                ? $this->localizationService->translate('mcp:status.sessions.exceeded', [
                    $count,
                    $this->sessionStore->getDirectory(),
                    $this->sessionStore->getRetentionSeconds(),
                ])
                : $this->localizationService->translate('mcp:status.sessions.ok', [$this->sessionStore->getDirectory()]),
            $exceeded ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::OK,
        );
    }

    private function cleanupStatus(): Status
    {
        $lastRun = $this->registry->get(McpCleanupCommand::REGISTRY_NAMESPACE, McpCleanupCommand::REGISTRY_LAST_RUN, 0);
        $lastRun = is_int($lastRun) ? $lastRun : 0;
        $value = $lastRun > 0 ? date('Y-m-d H:i', $lastRun) : '-';
        if ($lastRun > 0 && time() - $lastRun < McpCleanupCommand::MAX_AGE_SECONDS) {
            return new Status($this->localizationService->translate('mcp:status.cleanup.title'), $value);
        }

        return new Status(
            $this->localizationService->translate('mcp:status.cleanup.title'),
            $value,
            $this->localizationService->translate('mcp:status.cleanup.message'),
            ContextualFeedbackSeverity::WARNING,
        );
    }
}
