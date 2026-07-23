<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Dto;

final class RecordWriteResult
{
    /**
     * @param list<string> $strippedFields non-RTE fields whose HTML markup was removed before writing
     */
    public function __construct(
        public readonly int $uid,
        public readonly array $strippedFields = [],
    ) {}
}
