<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Exception;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;

class InvalidParameterException extends \InvalidArgumentException implements McpException
{
    use McpExceptionTrait;

    protected function defaultErrorType(): McpErrorType
    {
        return McpErrorType::InvalidParameter;
    }
}
