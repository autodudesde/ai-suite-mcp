<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Dto;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;

final class BatchOutcome
{
    /**
     * @param list<int|string>                                            $succeeded persisted UIDs of the successful items
     * @param list<array{table: string, uid: int|string, action: string}> $records   change descriptors of the successful
     *                                                                               items, in the same order as $succeeded.
     *                                                                               Only populated by handlers that report a
     *                                                                               table; consumers must treat it as optional.
     * @param ?McpErrorType                                               $errorType type of the first failed item, so a partial
     *                                                                               failure can be classified machine-readably.
     *                                                                               Null when nothing failed.
     */
    public function __construct(
        public readonly string $text,
        public readonly int $total,
        public readonly array $succeeded,
        public readonly int $failedCount,
        public readonly array $records = [],
        public readonly ?McpErrorType $errorType = null,
    ) {}

    public function hadError(): bool
    {
        return $this->failedCount > 0;
    }
}
