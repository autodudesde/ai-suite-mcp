<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

interface ToolPermissionPolicy
{
    /**
     * @return null|list<string>
     */
    public function scopePermissions(string $scope): ?array;

    /**
     * @return list<string>
     */
    public function toolPermissions(string $toolName): array;
}
