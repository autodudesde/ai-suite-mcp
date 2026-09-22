<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use TYPO3\CMS\Core\SingletonInterface;

class SurfaceSettingOverrides implements SingletonInterface
{
    private ?bool $rawMarkupWriteAllowed = null;

    /** @var list<string> */
    private array $additionalExcludedTables = [];

    /**
     * @param list<string> $additionalExcludedTables
     */
    public function apply(
        ?bool $rawMarkupWriteAllowed,
        array $additionalExcludedTables = [],
    ): void {
        $this->rawMarkupWriteAllowed = $rawMarkupWriteAllowed;
        $this->additionalExcludedTables = $additionalExcludedTables;
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
}
