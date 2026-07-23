<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Exception;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;

interface McpException
{
    public function getErrorType(): McpErrorType;

    /**
     * @return array<string, mixed>
     */
    public function getErrorContext(): array;
}
