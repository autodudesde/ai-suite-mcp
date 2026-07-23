<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array;

    /**
     * MCP behavioural hints (readOnlyHint, destructiveHint, idempotentHint, openWorldHint)
     * emitted in the tools/list response so clients can decide auto-approval.
     *
     * @return array<string, bool>
     */
    public function getAnnotations(): array;

    /**
     * @param array<string, mixed> $params Validated parameters
     */
    public function execute(array $params): CallToolResult;

    public function getRequiredScope(): ?string;
}
