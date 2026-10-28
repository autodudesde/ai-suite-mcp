<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

use AutoDudes\AiSuiteMcp\Mcp\Service\ToolPermissionPolicy;

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
        public readonly ?ToolPermissionPolicy $policy = null,
    ) {}
}
