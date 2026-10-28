<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\StatusReport;

use AutoDudes\AiSuite\Service\LocalizationService;
use Mcp\Server\Server;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class McpEnvironmentStatus implements StatusProviderInterface
{
    public const REQUIRED_PHP_EXTENSIONS = ['curl', 'json'];

    public function __construct(
        private readonly LocalizationService $localizationService,
    ) {}

    /**
     * @return list<Status>
     */
    public function getStatus(): array
    {
        $problems = [];

        $missing = array_values(array_filter(
            self::REQUIRED_PHP_EXTENSIONS,
            fn (string $extension): bool => !$this->isPhpExtensionLoaded($extension),
        ));
        if ([] !== $missing) {
            $problems[] = $this->localizationService->translate('mcp:status.environment.missingExtensions', [implode(', ', $missing)]);
        }

        if (!$this->isSdkLoaded()) {
            $problems[] = $this->localizationService->translate('mcp:status.environment.missingSdk');
        }

        if ([] === $problems) {
            return [new Status(
                $this->localizationService->translate('mcp:status.environment.title'),
                $this->localizationService->translate('mcp:status.environment.okValue'),
                $this->localizationService->translate('mcp:status.environment.okMessage', [implode(', ', self::REQUIRED_PHP_EXTENSIONS)]),
                ContextualFeedbackSeverity::OK,
            )];
        }

        return [new Status(
            $this->localizationService->translate('mcp:status.environment.title'),
            $this->localizationService->translate('mcp:status.environment.missingValue'),
            implode(' ', $problems),
            ContextualFeedbackSeverity::ERROR,
        )];
    }

    public function getLabel(): string
    {
        return 'LLL:EXT:ai_suite_mcp/Resources/Private/Language/locallang_mcp.xlf:aiSuite.mcp.status.environment.label';
    }

    protected function isPhpExtensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    protected function isSdkLoaded(): bool
    {
        return class_exists(Server::class);
    }
}
