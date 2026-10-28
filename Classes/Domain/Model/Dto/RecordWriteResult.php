<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

final class RecordWriteResult
{
    /**
     * @param list<string> $strippedFields
     */
    public function __construct(
        public readonly int $uid,
        public readonly array $strippedFields = [],
    ) {}
}
