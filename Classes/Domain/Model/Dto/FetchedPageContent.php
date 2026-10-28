<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

final class FetchedPageContent
{
    public function __construct(
        public readonly string $text,
        public readonly bool $rendered,
    ) {}
}
