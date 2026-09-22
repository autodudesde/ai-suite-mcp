<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Resource;

use AutoDudes\AiSuite\Domain\Repository\CustomPromptTemplateRepository;
use AutoDudes\AiSuite\Domain\Repository\ServerPromptTemplateRepository;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuiteMcp\Mcp\Utility\OperatingGuidelines;
use AutoDudes\AiSuiteMcp\Mcp\Utility\RequestParamsNormalizer;
use Mcp\Server\Server;
use Mcp\Types\CacheableResult;
use Mcp\Types\GetPromptResult;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\Prompt;
use Mcp\Types\PromptArgument;
use Mcp\Types\PromptMessage;
use Mcp\Types\Role;
use Mcp\Types\TextContent;
use Psr\Log\LoggerInterface;

class McpPromptHandler
{
    public function __construct(
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly CustomPromptTemplateRepository $customPromptTemplateRepository,
        private readonly ServerPromptTemplateRepository $serverPromptTemplateRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function registerHandlers(Server $server): void
    {
        $server->registerHandler('prompts/list', $this->handleList(...));
        $server->registerHandler('prompts/get', $this->handleGet(...));
    }

    private function handleList(mixed $params): ListPromptsResult
    {
        $prompts = [];

        // fallback for clients that miss the initialize instruction
        $prompts[] = new Prompt(
            name: 'operating-guidelines',
            description: 'Load mandatory workflow rules for this MCP server (write workflow, model selection, batch operations)',
        );

        $prompts[] = new Prompt(
            name: 'content-guidelines',
            description: 'Get content guidelines (tone, audience, style) for a specific page or section',
            arguments: [
                new PromptArgument(name: 'pageId', description: 'Page UID to get guidelines for', required: true),
                new PromptArgument(name: 'scope', description: 'Context: metadata, translation, editContent, pages'),
            ],
        );

        $prompts = array_merge(
            $prompts,
            $this->collectTemplatePrompts($this->customPromptTemplateRepository, 'custom'),
            $this->collectTemplatePrompts($this->serverPromptTemplateRepository, 'server'),
        );

        $result = new ListPromptsResult($prompts);
        $result->setCacheHints(0, CacheableResult::CACHE_SCOPE_PUBLIC);

        return $result;
    }

    private function handleGet(mixed $params): GetPromptResult
    {
        $params = RequestParamsNormalizer::toArray($params);
        $name = $params['name'] ?? '';
        $args = (array) ($params['arguments'] ?? []);

        if ('operating-guidelines' === $name) {
            return $this->userMessage(OperatingGuidelines::get());
        }

        if ('content-guidelines' === $name) {
            $pageId = (int) ($args['pageId'] ?? 0);
            $scope = $args['scope'] ?? 'pages';

            $instructions = $this->globalInstructionService->buildGlobalInstruction('', $scope, $pageId);

            return $this->userMessage('' !== $instructions
                ? "Content guidelines for this page:\n\n".$instructions
                : 'No specific content guidelines configured for this page. Use general best practices.');
        }

        if (str_starts_with($name, 'custom-')) {
            return $this->buildTemplateMessage(
                $this->customPromptTemplateRepository->findByUid((int) substr($name, 7)),
                'custom',
            );
        }

        if (str_starts_with($name, 'server-')) {
            return $this->buildTemplateMessage(
                $this->serverPromptTemplateRepository->findByUid((int) substr($name, 7)),
                'server',
            );
        }

        return $this->userMessage('Unknown prompt: '.$name);
    }

    private function userMessage(string $text): GetPromptResult
    {
        return new GetPromptResult([new PromptMessage(Role::USER, new TextContent($text))]);
    }

    /**
     * @return list<Prompt>
     */
    private function collectTemplatePrompts(
        CustomPromptTemplateRepository|ServerPromptTemplateRepository $repository,
        string $kind,
    ): array {
        try {
            $templates = $repository->findAllEnabled();
        } catch (\Throwable $e) {
            $this->logger->warning('McpPromptHandler: could not load '.$kind.' prompt templates — skipping', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $prompts = [];
        foreach ($templates as $template) {
            $prompts[] = new Prompt(
                name: $kind.'-'.$template['uid'],
                description: (string) ($template['name'] ?? ''),
                arguments: [
                    new PromptArgument(name: 'pageId', description: 'Page UID for context'),
                    new PromptArgument(name: 'language', description: 'ISO language code'),
                ],
            );
        }

        return $prompts;
    }

    /**
     * @param null|array<string, mixed> $template
     */
    private function buildTemplateMessage(?array $template, string $kind): GetPromptResult
    {
        if (null === $template) {
            return $this->userMessage('Template not found.');
        }

        $text = 'custom' === $kind
            ? (string) ($template['prompt'] ?? $template['name'] ?? '')
            : 'Use the "'.($template['name'] ?? '').'" template for '.($template['scope'] ?? 'general').' tasks.';

        return $this->userMessage($text);
    }
}
