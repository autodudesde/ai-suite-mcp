<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Exception;

final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfter,
    ) {
        parent::__construct($message);
    }
}
