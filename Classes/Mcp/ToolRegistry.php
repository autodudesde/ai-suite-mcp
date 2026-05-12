<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry for all MCP tools.
 *
 * Currently supports tool registration via DI tags (aisuite.mcp.tool).
 * Will be extended in a later phase to also support:
 * - DB-defined Custom Tools (DynamicCustomToolFactory)
 * - PSR-14 Event-based tools (CollectToolsEvent)
 */
class ToolRegistry
{
    /**
     * Built-in tool namespace. Only tools from this namespace may extend
     * AbstractTool directly. Third-party tools MUST use AbstractCustomTool.
     */
    private const BUILTIN_NAMESPACE = 'AutoDudes\AiSuiteMcp\Mcp\Tool\\';

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param array<string, mixed> $taggedTools
     */
    public function __construct(
        #[AutowireIterator('aisuite.mcp.tool')]
        iterable $taggedTools,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($taggedTools as $tool) {
            if ($this->validateToolOrigin($tool)) {
                $this->tools[$tool->getName()] = $tool;
            }
        }
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function getTool(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Validates that third-party tools extend AbstractCustomTool.
     */
    private function validateToolOrigin(ToolInterface $tool): bool
    {
        $className = get_class($tool);

        // Built-in tools: always allowed
        if (str_starts_with($className, self::BUILTIN_NAMESPACE)) {
            return true;
        }

        // AbstractCustomTool: allowed (enforces server route)

        // Third-party extending AbstractTool directly: rejected
        if ($tool instanceof AbstractTool
            && !str_starts_with($className, 'AutoDudes\AiSuite\\')
            && !str_starts_with($className, 'AutoDudes\AiSuiteMcp\\')
        ) {
            $this->logger->warning('Rejected third-party MCP tool: must extend AbstractCustomTool', [
                'class' => $className,
                'tool_name' => $tool->getName(),
            ]);

            return false;
        }

        return true;
    }
}
