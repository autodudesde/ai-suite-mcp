<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Exception;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;

trait McpExceptionTrait
{
    private ?McpErrorType $errorType = null;

    /**
     * @var array<string, mixed>
     */
    private array $errorContext = [];

    public function getErrorType(): McpErrorType
    {
        return $this->errorType ?? $this->defaultErrorType();
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrorContext(): array
    {
        return $this->errorContext;
    }

    public function withErrorType(McpErrorType $errorType): static
    {
        $this->errorType = $errorType;

        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withErrorContext(array $context): static
    {
        $this->errorContext = $context;

        return $this;
    }

    abstract protected function defaultErrorType(): McpErrorType;
}
