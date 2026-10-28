<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

use TYPO3\CMS\Core\Resource\Folder;

final class MediaUploadPlan
{
    /**
     * @param list<string> $missingFolders
     */
    public function __construct(
        public readonly string $url,
        public readonly string $content,
        public readonly string $fileName,
        public readonly string $folderIdentifier,
        public readonly Folder $writableFolder,
        public readonly array $missingFolders,
        public readonly bool $replace,
    ) {}
}
