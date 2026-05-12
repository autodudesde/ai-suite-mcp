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
    private ?array $excluded = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
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
        if (null !== $this->excluded) {
            return $this->excluded;
        }

        try {
            $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return $this->excluded = [];
        }

        return $this->excluded = array_values(array_filter(
            array_map('trim', explode(',', (string) ($extConf['mcpExcludedTables'] ?? ''))),
        ));
    }
}
