<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\SingletonInterface;

class SearchableTablesService implements SingletonInterface
{
    /** @var array<string, list<string>> */
    private array $tables = [];

    public function __construct(
        private readonly McpExcludedTablesService $excludedTables,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $except
     *
     * @return list<string>
     */
    public function getSearchableTables(array $except = []): array
    {
        $signature = implode(',', $this->excludedTables->getExcluded());
        $this->tables[$signature] ??= $this->detect();

        return [] === $except
            ? $this->tables[$signature]
            : array_values(array_diff($this->tables[$signature], $except));
    }

    /**
     * @return list<string>
     */
    private function detect(): array
    {
        try {
            $candidates = $this->tcaCompatibilityService->getAllTableNames();
        } catch (\Throwable $e) {
            $this->logger->warning('Searchable table scan failed', ['error' => $e->getMessage()]);

            return [];
        }

        $tables = [];
        foreach ($candidates as $table) {
            if ($this->isSearchable($table)) {
                $tables[] = $table;
            }
        }

        sort($tables);

        return $tables;
    }

    private function isSearchable(string $table): bool
    {
        if ($this->excludedTables->isExcluded($table)) {
            return false;
        }

        try {
            if (!$this->tcaCompatibilityService->hasTable($table)) {
                return false;
            }
            if (!$this->tcaCompatibilityService->canExistOnPages($table)) {
                return false;
            }
            if ($this->tcaCompatibilityService->ignoresWebMountRestriction($table)) {
                return false;
            }

            return [] !== $this->tcaCompatibilityService->getSearchableTextFields($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
