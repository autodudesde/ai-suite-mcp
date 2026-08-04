<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\SingletonInterface;

class ChildTableRegistryService implements SingletonInterface
{
    private const RELATION_TYPES = ['inline', 'file'];

    private const NEVER_CHILD = ['sys_file_reference', 'pages', 'tt_content'];

    /** @var null|list<string> */
    private ?array $childTables = null;

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<string>
     */
    public function getChildTables(): array
    {
        if (null !== $this->childTables) {
            return $this->childTables;
        }

        try {
            $candidates = [];
            foreach ($this->tcaCompatibilityService->getAllTableNames() as $parentTable) {
                foreach ($this->tcaCompatibilityService->getColumnConfigs($parentTable) as $config) {
                    if (!in_array($config['type'] ?? '', self::RELATION_TYPES, true)) {
                        continue;
                    }
                    $childTable = (string) ($config['foreign_table'] ?? '');
                    $parentField = (string) ($config['foreign_field'] ?? '');
                    if ('' === $childTable || '' === $parentField) {
                        continue;
                    }

                    $candidates[$childTable] = true;
                }
            }

            $tables = [];
            foreach (array_keys($candidates) as $table) {
                if (in_array($table, self::NEVER_CHILD, true)) {
                    continue;
                }
                if (!$this->tcaCompatibilityService->hasTable($table)) {
                    continue;
                }
                // A rootLevel table sits on pid 0 and can therefore never be inside a webmount.
                if ($this->tcaCompatibilityService->isRootLevel($table)) {
                    continue;
                }

                $tables[] = $table;
            }
            sort($tables);
        } catch (\Throwable $e) {
            $this->logger->warning('Child table scan failed', ['error' => $e->getMessage()]);

            return $this->childTables = [];
        }

        return $this->childTables = $tables;
    }

    public function isChildTable(string $table): bool
    {
        return in_array($table, $this->getChildTables(), true);
    }
}
