<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use TYPO3\CMS\Core\SingletonInterface;

class SurfaceSettingOverrides implements SingletonInterface
{
    private ?bool $rawMarkupWriteAllowed = null;

    /** @var list<string> */
    private array $additionalExcludedTables = [];

    /** @var list<string> */
    private array $additionalSearchTables = [];

    /** @var list<string> */
    private array $searchTablesExcludedFromAuto = [];

    /**
     * @param list<string> $additionalExcludedTables
     * @param list<string> $additionalSearchTables
     * @param list<string> $searchTablesExcludedFromAuto
     */
    public function apply(
        ?bool $rawMarkupWriteAllowed,
        array $additionalExcludedTables = [],
        array $additionalSearchTables = [],
        array $searchTablesExcludedFromAuto = [],
    ): void {
        $this->rawMarkupWriteAllowed = $rawMarkupWriteAllowed;
        $this->additionalExcludedTables = $additionalExcludedTables;
        $this->additionalSearchTables = $additionalSearchTables;
        $this->searchTablesExcludedFromAuto = $searchTablesExcludedFromAuto;
    }

    public function reset(): void
    {
        $this->apply(null);
    }

    public function allowsRawMarkupWrite(): ?bool
    {
        return $this->rawMarkupWriteAllowed;
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExcludedTables(): array
    {
        return $this->additionalExcludedTables;
    }

    /**
     * @return list<string>
     */
    public function getAdditionalSearchTables(): array
    {
        return $this->additionalSearchTables;
    }

    /**
     * @return list<string>
     */
    public function getSearchTablesExcludedFromAuto(): array
    {
        return $this->searchTablesExcludedFromAuto;
    }

    public function getSignature(): string
    {
        return json_encode([
            $this->rawMarkupWriteAllowed,
            $this->additionalExcludedTables,
            $this->additionalSearchTables,
            $this->searchTablesExcludedFromAuto,
        ], JSON_THROW_ON_ERROR);
    }
}
