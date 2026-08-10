<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool;

final class ToolAccessContext
{
    public const VIA_MCP = 'mcp';

    public const VIA_CHEDDI = 'cheddi';

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly array $scopes,
        public readonly string $via,
    ) {}
}
