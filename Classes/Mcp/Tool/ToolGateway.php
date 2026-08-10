<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool;

use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\Log\LoggerInterface;

class ToolGateway
{
    private const LOGGED_ERROR_LENGTH = 500;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly PermissionService $permissionService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, ToolInterface> $hostTools
     *
     * @return list<ToolInterface>
     */
    public function listTools(ToolAccessContext $context, array $hostTools = []): array
    {
        $tools = [];
        foreach ($this->toolRegistry->getTools() as $tool) {
            if ($this->permissionService->isToolAvailable($tool->getName(), $context->scopes)) {
                $tools[] = $tool;
            }
        }

        return [...$tools, ...array_values($hostTools)];
    }

    /**
     * @param array<string, mixed>         $arguments
     * @param array<string, ToolInterface> $hostTools
     */
    public function callTool(string $name, array $arguments, ToolAccessContext $context, array $hostTools = []): CallToolResult
    {
        $startTime = microtime(true);
        $this->logger->info('MCP tool call', [
            'tool' => $name,
            'via' => $context->via,
            'arguments' => array_keys($arguments),
        ]);

        if (isset($hostTools[$name])) {
            return $this->run($hostTools[$name], $name, $arguments, $startTime, $context);
        }

        $tool = $this->toolRegistry->getTool($name);
        if (null === $tool) {
            $this->logger->warning('MCP unknown tool requested', ['tool' => $name, 'via' => $context->via]);

            return $this->failure(
                $name,
                sprintf('Unknown tool: %s. Use tools/list to see available tools.', $name),
                $startTime,
                $context,
                logResult: false,
            );
        }

        try {
            $this->permissionService->validateToolAccess($name, $context->scopes);
        } catch (\Throwable $e) {
            return $this->failure(
                $name,
                sprintf('Permission denied for tool "%s": %s', $name, $e->getMessage()),
                $startTime,
                $context,
            );
        }

        return $this->run($tool, $name, $arguments, $startTime, $context);
    }

    public function textOf(CallToolResult $result): string
    {
        $text = '';
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $text .= $content->text;
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function run(
        ToolInterface $tool,
        string $name,
        array $arguments,
        float $startTime,
        ToolAccessContext $context,
    ): CallToolResult {
        try {
            $result = $tool->execute($arguments);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP tool execution failed', [
                'tool' => $name,
                'via' => $context->via,
                'duration_ms' => $this->durationMs($startTime),
                'exception' => $e->getMessage(),
            ]);

            return $this->failure(
                $name,
                sprintf('Tool "%s" raised an exception: %s', $name, $e->getMessage()),
                $startTime,
                $context,
                logResult: false,
            );
        }

        if ($result->isError ?? false) {
            $this->logger->warning('MCP tool returned an error', [
                'tool' => $name,
                'via' => $context->via,
                'duration_ms' => $this->durationMs($startTime),
                'errorType' => $result->structuredContent['error']['type'] ?? null,
                'message' => mb_substr($this->textOf($result), 0, self::LOGGED_ERROR_LENGTH),
            ]);

            return $result;
        }

        $this->logger->info('MCP tool result', [
            'tool' => $name,
            'via' => $context->via,
            'duration_ms' => $this->durationMs($startTime),
            'isError' => false,
        ]);

        return $result;
    }

    private function failure(
        string $name,
        string $message,
        float $startTime,
        ToolAccessContext $context,
        bool $logResult = true,
    ): CallToolResult {
        if ($logResult) {
            $this->logger->warning('MCP tool call refused', [
                'tool' => $name,
                'via' => $context->via,
                'duration_ms' => $this->durationMs($startTime),
                'message' => mb_substr($message, 0, self::LOGGED_ERROR_LENGTH),
            ]);
        }

        return new CallToolResult([new TextContent($message)], isError: true);
    }

    private function durationMs(float $startTime): int
    {
        return (int) round((microtime(true) - $startTime) * 1000);
    }
}
