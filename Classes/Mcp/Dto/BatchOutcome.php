<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Dto;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;

final class BatchOutcome
{
    /**
     * @param list<int|string>                                            $succeeded
     * @param list<array{table: string, uid: int|string, action: string}> $records
     */
    public function __construct(
        public readonly string $text,
        public readonly int $total,
        public readonly array $succeeded,
        public readonly int $failedCount,
        public readonly array $records = [],
        public readonly ?McpErrorType $errorType = null,
        public readonly int $skipped = 0,
    ) {}

    public function hadError(): bool
    {
        return $this->failedCount > 0;
    }
}
