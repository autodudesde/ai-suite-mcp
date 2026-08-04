<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Exception;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;

// A batch item that had nothing to do. Not a failure, so it must not mark the call as failed.
class SkippedItemException extends \RuntimeException implements McpException
{
    use McpExceptionTrait;

    protected function defaultErrorType(): McpErrorType
    {
        return McpErrorType::NotFound;
    }
}
