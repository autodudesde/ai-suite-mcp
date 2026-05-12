<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuiteMcp\Mcp\Resource\McpPromptHandler;
use AutoDudes\AiSuiteMcp\Mcp\Resource\McpResourceHandler;
use Mcp\Server\Server;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Factory that creates and configures the MCP Server instance.
 *
 * Registers JSON-RPC handlers for tools/list and tools/call.
 * Tools are filtered by the current token's scopes.
 */
class McpServerFactory
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly McpUserContext $userContext,
        private readonly McpResourceHandler $resourceHandler,
        private readonly McpPromptHandler $promptHandler,
        private readonly SendRequestService $sendRequestService,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.hash')]
        private readonly FrontendInterface $cache,
    ) {}

    public function createServer(): Server
    {
        $server = new Server('ai-suite');
        $this->registerToolHandlers($server);

        return $server;
    }

    private function registerToolHandlers(Server $server): void
    {
        $server->registerHandler('tools/list', $this->handleToolsList(...));
        $server->registerHandler('tools/call', $this->handleToolsCall(...));

        $this->resourceHandler->registerHandlers($server);

        $this->promptHandler->registerHandlers($server);
    }

    /**
     * Handler for tools/list — returns all tools the current token has access to.
     * AI tools are marked as unavailable when the AI Suite Server is unreachable.
     *
     * @return array<string, mixed>
     */
    private function handleToolsList(mixed $params): array
    {
        $serverAvailable = $this->checkServerAvailability();
        $tokenScopes = $this->userContext->getScopes();
        $tools = [];

        foreach ($this->toolRegistry->getTools() as $tool) {
            $requiredScope = $tool->getRequiredScope();

            // Filter by token scopes
            if (null !== $requiredScope && !in_array($requiredScope, $tokenScopes, true)) {
                continue;
            }

            $description = $tool->getDescription();

            // Mark AI tools as unavailable when server is down
            if (!$serverAvailable && $this->isAiTool($tool)) {
                $description .= ' [Currently unavailable — AI Suite Server is temporarily unreachable]';
            }

            $tools[] = [
                'name' => $tool->getName(),
                'description' => $description,
                'inputSchema' => $tool->getSchema(),
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * Handler for tools/call — executes a tool by name.
     */
    private function handleToolsCall(mixed $params): CallToolResult
    {
        $params = $this->normalizeParams($params);
        $toolName = $params['name'] ?? '';
        $arguments = (array) ($params['arguments'] ?? []);
        $startTime = microtime(true);

        $this->logger->info('MCP tool call', [
            'tool' => $toolName,
            'user' => $this->userContext->getBeUserUid(),
            'client' => $this->userContext->getClientId(),
            'arguments' => array_keys($arguments),
        ]);

        $tool = $this->toolRegistry->getTool($toolName);

        if (null === $tool) {
            $this->logger->warning('MCP unknown tool requested', ['tool' => $toolName]);

            $duration = $this->getDurationMs($startTime);
            $this->logger->info('MCP tool result', [
                'tool' => $toolName,
                'duration_ms' => $duration,
                'isError' => true,
            ]);

            return new CallToolResult(
                [new TextContent(sprintf('Unknown tool: %s. Use tools/list to see available tools.', $toolName))],
                isError: true,
            );
        }

        $result = $tool->execute($arguments);

        $duration = $this->getDurationMs($startTime);
        $this->logger->info('MCP tool result', [
            'tool' => $toolName,
            'duration_ms' => $duration,
            'isError' => $result->isError ?? false,
        ]);

        return $result;
    }

    /**
     * Check if the AI Suite Server is reachable.
     */
    private function checkServerAvailability(): bool
    {
        $cacheKey = 'aisuite_mcp_server_available';

        $cached = $this->cache->get($cacheKey);
        if (false !== $cached) {
            return 1 === (int) $cached;
        }

        $available = $this->sendRequestService->isServerReachable();
        $this->cache->set($cacheKey, $available ? 1 : 0, ['mcp'], 120);

        return $available;
    }

    /**
     * Determine if a tool requires the AI Suite Server.
     */
    private function isAiTool(ToolInterface $tool): bool
    {
        $scope = $tool->getRequiredScope();

        return null !== $scope && !in_array($scope, ['mcp:read', 'mcp:write', 'mcp:manage'], true);
    }

    /**
     * Convert SDK typed params to a plain array for uniform access.
     *
     * @return array<string, mixed>
     */
    private function normalizeParams(mixed $params): array
    {
        if (is_array($params)) {
            return $params;
        }

        if (is_object($params) && method_exists($params, 'jsonSerialize')) {
            return (array) $params->jsonSerialize();
        }

        if (is_object($params)) {
            return (array) $params;
        }

        return [];
    }

    private function getDurationMs(float $startTime): int
    {
        return (int) round((microtime(true) - $startTime) * 1000);
    }
}
