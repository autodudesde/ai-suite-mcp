<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Resource;

use AutoDudes\AiSuite\Domain\Repository\CustomPromptTemplateRepository;
use AutoDudes\AiSuite\Domain\Repository\ServerPromptTemplateRepository;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuiteMcp\Mcp\OperatingGuidelines;
use Mcp\Server\Server;
use Psr\Log\LoggerInterface;

/**
 * Registers MCP Prompts — interactive templates with arguments.
 *
 * Custom templates (user-created): full prompt text included — `custom-{uid}`.
 * Server templates (shipped): name + description only (IP protection) — `server-{uid}`.
 * content-guidelines: loads Global Instructions for a page.
 */
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

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function handleList(?array $params): array
    {
        $prompts = [];

        // Operating guidelines prompt (fallback for clients that miss the initialize instruction)
        $prompts[] = [
            'name' => 'operating-guidelines',
            'description' => 'Load mandatory workflow rules for this MCP server (write workflow, model selection, batch operations)',
            'arguments' => [],
        ];

        // Content guidelines prompt
        $prompts[] = [
            'name' => 'content-guidelines',
            'description' => 'Get content guidelines (tone, audience, style) for a specific page or section',
            'arguments' => [
                ['name' => 'pageId', 'description' => 'Page UID to get guidelines for', 'required' => true],
                ['name' => 'scope', 'description' => 'Context: metadata, translation, editContent, pages', 'required' => false],
            ],
        ];

        $prompts = array_merge(
            $prompts,
            $this->collectTemplatePrompts($this->customPromptTemplateRepository, 'custom'),
            $this->collectTemplatePrompts($this->serverPromptTemplateRepository, 'server'),
        );

        return ['prompts' => $prompts];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function handleGet(?array $params): array
    {
        $name = $params['name'] ?? '';
        $args = (array) ($params['arguments'] ?? []);

        if ('operating-guidelines' === $name) {
            return [
                'messages' => [[
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => OperatingGuidelines::get()],
                ]],
            ];
        }

        if ('content-guidelines' === $name) {
            $pageId = (int) ($args['pageId'] ?? 0);
            $scope = $args['scope'] ?? 'pages';

            $instructions = $this->globalInstructionService->buildGlobalInstruction('', $scope, $pageId);

            return [
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => '' !== $instructions
                            ? "Content guidelines for this page:\n\n".$instructions
                            : 'No specific content guidelines configured for this page. Use general best practices.',
                    ],
                ]],
            ];
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

        return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Unknown prompt: '.$name]]]];
    }

    /**
     * @return list<array<string, mixed>>
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
            $prompts[] = [
                'name' => $kind.'-'.$template['uid'],
                'description' => (string) ($template['name'] ?? ''),
                'arguments' => [
                    ['name' => 'pageId', 'description' => 'Page UID for context', 'required' => false],
                    ['name' => 'language', 'description' => 'ISO language code', 'required' => false],
                ],
            ];
        }

        return $prompts;
    }

    /**
     * Custom templates expose the full prompt text; server templates only metadata (IP protection).
     *
     * @param null|array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    private function buildTemplateMessage(?array $template, string $kind): array
    {
        if (null === $template) {
            return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Template not found.']]]];
        }

        $text = 'custom' === $kind
            ? (string) ($template['prompt'] ?? $template['name'] ?? '')
            : 'Use the "'.($template['name'] ?? '').'" template for '.($template['scope'] ?? 'general').' tasks.';

        return [
            'messages' => [[
                'role' => 'user',
                'content' => ['type' => 'text', 'text' => $text],
            ]],
        ];
    }
}
