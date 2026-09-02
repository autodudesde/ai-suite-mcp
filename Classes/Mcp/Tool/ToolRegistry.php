<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class ToolRegistry
{
    private const ALLOWED_NAMESPACES = [
        'AutoDudes\AiSuiteMcp\\',
        'AutoDudes\AiSuite\\',
        'AutoDudes\Cheddi\\',
    ];

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

    private function validateToolOrigin(ToolInterface $tool): bool
    {
        $className = get_class($tool);

        foreach (self::ALLOWED_NAMESPACES as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return true;
            }
        }

        $this->logger->warning('Rejected third-party MCP tool: class is outside the allowed namespaces', [
            'class' => $className,
            'tool_name' => $tool->getName(),
            'allowed_namespaces' => self::ALLOWED_NAMESPACES,
        ]);

        return false;
    }
}
