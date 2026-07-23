<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Exception;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;

class InsufficientScopeException extends \RuntimeException implements McpException
{
    use McpExceptionTrait;

    protected function defaultErrorType(): McpErrorType
    {
        return McpErrorType::InsufficientScope;
    }
}
