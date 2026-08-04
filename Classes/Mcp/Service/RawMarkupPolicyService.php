<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

class RawMarkupPolicyService implements SingletonInterface
{
    public const SETTING_KEY = 'mcpAllowRawHtmlWrite';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function isRawMarkupWriteAllowed(): bool
    {
        try {
            return (bool) ($this->extensionConfiguration->get('ai_suite_mcp')[self::SETTING_KEY] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }
}
