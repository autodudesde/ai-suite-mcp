<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

final class FetchedMedia
{
    public function __construct(
        public readonly string $tempFilePath,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $sourceUrl,
    ) {}
}
