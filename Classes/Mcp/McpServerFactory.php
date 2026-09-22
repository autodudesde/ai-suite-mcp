<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuiteMcp\Mcp\Resource\McpPromptHandler;
use AutoDudes\AiSuiteMcp\Mcp\Resource\McpResourceHandler;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolAccessContext;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolGateway;
use AutoDudes\AiSuiteMcp\Mcp\Utility\RequestParamsNormalizer;
use Mcp\Server\Server;
use Mcp\Types\CacheableResult;
use Mcp\Types\CallToolResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\Tool;
use Mcp\Types\ToolAnnotations;
use Mcp\Types\ToolInputSchema;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

class McpServerFactory
{
    public function __construct(
        private readonly ToolGateway $toolGateway,
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
        $server = new Server('ai-suite', $this->logger, ExtensionManagementUtility::getExtensionVersion('ai_suite_mcp') ?: '0.0.0');
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

    private function handleToolsList(mixed $params): ListToolsResult
    {
        $serverAvailable = $this->checkServerAvailability();
        $tools = [];

        foreach ($this->toolGateway->listTools($this->accessContext()) as $tool) {
            $description = $tool->getDescription();

            if (!$serverAvailable && $tool instanceof AbstractAiTool) {
                $description .= ' [Currently unavailable — AI Suite Server is temporarily unreachable]';
            }

            try {
                $inputSchema = $this->toInputSchema($tool->getSchema());
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('MCP tool omitted from tools/list: malformed inputSchema', [
                    'tool' => $tool->getName(),
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            $annotations = $tool->getAnnotations();

            $tools[] = new Tool(
                name: $tool->getName(),
                inputSchema: $inputSchema,
                description: $description,
                annotations: new ToolAnnotations(
                    readOnlyHint: $annotations['readOnlyHint'] ?? null,
                    destructiveHint: $annotations['destructiveHint'] ?? null,
                    idempotentHint: $annotations['idempotentHint'] ?? null,
                    openWorldHint: $annotations['openWorldHint'] ?? null,
                ),
            );
        }

        usort($tools, static fn (Tool $a, Tool $b): int => strcmp($a->name, $b->name));

        $result = new ListToolsResult($tools);
        $result->setCacheHints(0, CacheableResult::CACHE_SCOPE_PRIVATE);

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function toInputSchema(array $schema): ToolInputSchema
    {
        if (($schema['properties'] ?? null) instanceof \stdClass) {
            $schema['properties'] = (array) $schema['properties'];
        }

        $schema['type'] ??= 'object';

        return ToolInputSchema::fromArray($schema);
    }

    private function handleToolsCall(mixed $params): CallToolResult
    {
        $params = RequestParamsNormalizer::toArray($params);

        return $this->toolGateway->callTool(
            (string) ($params['name'] ?? ''),
            (array) ($params['arguments'] ?? []),
            $this->accessContext(),
        );
    }

    private function accessContext(): ToolAccessContext
    {
        return new ToolAccessContext($this->userContext->getScopes(), ToolAccessContext::VIA_MCP);
    }

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
}
