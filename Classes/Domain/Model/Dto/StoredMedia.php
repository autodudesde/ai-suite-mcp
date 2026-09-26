<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

use TYPO3\CMS\Core\Resource\File;

final class StoredMedia
{
    public const CREATED = 'created';
    public const RENAMED = 'renamed';
    public const REPLACED = 'replaced';

    /**
     * @param self::CREATED|self::RENAMED|self::REPLACED $outcome
     */
    public function __construct(
        public readonly File $file,
        public readonly string $outcome,
    ) {}
}
