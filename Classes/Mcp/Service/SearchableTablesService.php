<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

class SearchableTablesService implements SingletonInterface
{
    /** @var null|list<string> */
    private ?array $additional = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly McpExcludedTablesService $excludedTables,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly ChildTableRegistryService $childTableRegistry,
    ) {}

    /**
     * @return list<string>
     */
    public function getAdditionalTables(): array
    {
        if (null !== $this->additional) {
            return $this->additional;
        }

        $extConf = [];

        try {
            $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            // An unconfigured extension still gets the auto-detected child tables.
        }

        $excludedFromAuto = $this->parseList($extConf['mcpExcludeAdditionalTablesFromSearch'] ?? '');
        $configured = $this->parseList($extConf['mcpSearchAdditionalTables'] ?? '');

        $candidates = array_merge(
            array_diff($this->childTableRegistry->getChildTables(), $excludedFromAuto),
            $configured,
        );

        $tables = [];
        foreach ($candidates as $table) {
            if (in_array($table, ['pages', 'tt_content'], true) || $this->excludedTables->isExcluded($table)) {
                continue;
            }
            if (!$this->tcaCompatibilityService->hasTable($table)) {
                continue;
            }

            try {
                $searchFields = $this->tcaCompatibilityService->getSearchableTextFields($table);
            } catch (\Throwable) {
                continue;
            }
            if ([] === $searchFields) {
                continue;
            }

            $tables[] = $table;
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $this->additional = $tables;
    }

    /**
     * @return list<string>
     */
    private function parseList(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
