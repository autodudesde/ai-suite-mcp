<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * JSON Schema for the tool's input parameters.
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array;

    /**
     * Execute the tool with the given parameters.
     *
     * @param array<string, mixed> $params Validated parameters
     */
    public function execute(array $params): CallToolResult;

    /**
     * OAuth scope required to use this tool. Null means no scope check.
     */
    public function getRequiredScope(): ?string;
}
