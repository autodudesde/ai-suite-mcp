<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Exception;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;

class RefusedRequestException extends \RuntimeException implements McpException
{
    use McpExceptionTrait;

    public static function invalidParameter(string $message, ?\Throwable $previous = null): self
    {
        return (new self($message, 0, $previous))->withErrorType(McpErrorType::InvalidParameter);
    }

    public static function notFound(string $message, ?\Throwable $previous = null): self
    {
        return (new self($message, 0, $previous))->withErrorType(McpErrorType::NotFound);
    }

    public static function unsupportedHtml(string $message): self
    {
        return (new self($message))->withErrorType(McpErrorType::UnsupportedHtml);
    }

    protected function defaultErrorType(): McpErrorType
    {
        return McpErrorType::InvalidParameter;
    }
}
