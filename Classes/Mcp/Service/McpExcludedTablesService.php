<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

class McpExcludedTablesService implements SingletonInterface
{
    /** @var null|list<string> */
    private ?array $configured = null;

    /** @var array<string, list<string>> */
    private array $merged = [];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SurfaceSettingOverrides $surfaceOverrides,
    ) {}

    public function isExcluded(string $table): bool
    {
        return in_array($table, $this->getExcluded(), true);
    }

    /**
     * @return list<string>
     */
    public function getExcluded(): array
    {
        $additional = $this->surfaceOverrides->getAdditionalExcludedTables();
        if ([] === $additional) {
            return $this->getConfigured();
        }

        $signature = implode(',', $additional);

        return $this->merged[$signature] ??= array_values(array_unique([
            ...$this->getConfigured(),
            ...$additional,
        ]));
    }

    /**
     * @return list<string>
     */
    private function getConfigured(): array
    {
        if (null !== $this->configured) {
            return $this->configured;
        }

        try {
            $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return $this->configured = [];
        }

        return $this->configured = array_values(array_filter(
            array_map('trim', explode(',', (string) ($extConf['mcpExcludedTables'] ?? ''))),
        ));
    }
}
