<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

use TYPO3\CMS\Core\Resource\Folder;

final class TargetFolderPlan
{
    /**
     * @param list<string> $missingFolders
     */
    public function __construct(
        public readonly string $identifier,
        public readonly Folder $writableFolder,
        public readonly array $missingFolders,
    ) {}
}
