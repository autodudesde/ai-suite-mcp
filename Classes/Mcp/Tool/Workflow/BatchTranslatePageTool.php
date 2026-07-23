<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuite\Service\WorkflowProcessingService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class BatchTranslatePageTool extends AbstractAiTool
{
    // Gating scope = the mass-action scope the gate actually checks (TOOL_SCOPE_MAP).
    // The AI feature permission is verified on top, in validatePermissions().
    protected ?string $requiredScope = 'mcp:workflow';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly WorkflowProcessingService $workflowProcessingService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'batchTranslatePage';
    }

    public function getDescription(): string
    {
        return 'Translate multiple pages with an external AI model (costs credits). '
            .DescriptionSnippets::BATCH_ASYNC;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Array of page UIDs to translate.',
                ],
                'targetLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO target language code (de, en, fr, es, ...).',
                ]),
                'sourceLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO source language. Default: site default language.',
                ]),
                'translationScope' => [
                    'type' => 'string',
                    'enum' => ['all', 'metadata', 'content'],
                    'default' => 'all',
                    'description' => 'What to translate: "all" (metadata + content), "metadata" (SEO fields only), "content" (content elements only).',
                ],
                'model' => ['type' => 'string', 'description' => 'Translation model identifier (e.g. DeepL). Omit to list available models.'],
            ],
            'required' => ['pageIds', 'targetLanguage'],
        ];
    }

    protected function validatePermissions(): void
    {
        parent::validatePermissions();
        $this->permissionService->validateFeatureScope('mcp:translate');
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageIds = $params['pageIds'] ?? [];
        $model = (string) ($params['model'] ?? '');
        $targetLanguage = (string) $params['targetLanguage'];
        $translationScope = (string) ($params['translationScope'] ?? 'all');

        if (empty($pageIds)) {
            return $this->textError('pageIds must be a non-empty array.');
        }

        if ('' === $model) {
            $pageCount = count($pageIds);
            $text = sprintf("## Translate %d pages to %s\n\n", $pageCount, $targetLanguage);

            $text .= "**Option 1 — Async (recommended for many pages):**\n";
            $text .= "  An external AI model translates all pages simultaneously in the background.\n";
            $text .= "  ⚡ You can continue working while the server translates — no waiting.\n";
            $text .= "  Translatable content is collected automatically.\n\n";

            $text .= "**Option 2 — Sequential:**\n";
            $text .= "  You translate each page individually using translatePage.\n";
            $text .= "  ⏱ Takes longer — you must wait for each page to be processed.\n\n";

            $text .= "### Available models for Option 1:\n\n";

            $modelsResult = $this->listAvailableModels(
                $this->libraryService,
                GenerationLibraryEnumeration::TRANSLATE,
                'translate',
                ['text'],
                ['text' => 'Translation models'],
            );

            foreach ($modelsResult->content as $content) {
                if ($content instanceof TextContent) {
                    $text .= $content->text;
                }
            }
            $text .= "\n\nPresent both options to the user and ask which approach they prefer.";

            return $this->textResult($text);
        }

        $this->permissionService->validateModelAccess($model);

        $sourceLanguage = $params['sourceLanguage'] ?? '';
        if ('' === $sourceLanguage) {
            $sourceLanguage = $this->resolveLanguageIsoCode('', $pageIds[0] ?? 1);
        }

        $sourceLanguageUid = $this->recordAccess->resolveLanguageUid($sourceLanguage, $pageIds[0] ?? 1);
        $targetLanguageUid = $this->recordAccess->resolveLanguageUid($targetLanguage, $pageIds[0] ?? 1);

        // resolveLanguageUid() answers 0 both for "the default language" and for "could not resolve
        // this code" (unknown code, or the site lookup threw). Without this check an unresolvable
        // target silently becomes the default language, and the batch spends credits translating
        // every page into the language it is already in. The targetLanguage enum narrows this but
        // does not close it: it is a union over all sites, so a code that is valid for another site
        // still resolves to 0 here, and no enum is offered at all when the languages are unknown.
        if (0 === $targetLanguageUid) {
            return $this->textError("Language \"{$targetLanguage}\" is not configured for this site.");
        }

        // assertLanguageAccess once (target language is tool-global, fail-fast)
        $this->recordAccess->assertLanguageAccess($targetLanguageUid);

        // Filter valid pages (exists + not excluded + CONTENT_EDIT permission, skip-and-report).
        $pages = [];
        $skipped = [];

        foreach ($pageIds as $pageId) {
            $pageId = (int) $pageId;

            try {
                $validated = $this->validatePageForAi($pageId, Permission::CONTENT_EDIT);
            } catch (InsufficientPermissionException $e) {
                $this->logger->warning('BatchTranslatePage: skipping page — insufficient permission', [
                    'pageId' => $pageId,
                    'reason' => $e->getMessage(),
                ]);
                $skipped[] = $pageId;

                continue;
            }
            if ($validated instanceof CallToolResult) {
                $skipped[] = $pageId;

                continue;
            }

            $pages[$pageId] = $validated['slug'] ?? '';
        }

        if (empty($pages)) {
            return $this->textError('No valid pages found to translate.');
        }

        $parentUuid = $this->uuidService->generateUuid();

        $request = $this->userContext->getServerRequest();

        $result = $this->workflowProcessingService->processPageTranslation(
            $pages,
            $parentUuid,
            $translationScope,
            strtoupper($sourceLanguage),
            strtoupper($targetLanguage),
            $sourceLanguageUid,
            $targetLanguageUid,
            $request,
            model: $model,
        );

        $payload = $result['payload'];
        $bulkPayload = $result['bulkPayload'];
        $failedPages = $result['failedPages'];

        if (empty($payload)) {
            return $this->textError('No translatable content found for the given pages.');
        }

        $serverResult = $this->sendRequestService->sendDataRequest(
            'createMassAction',
            [
                'uuid' => $parentUuid,
                'payload' => $payload,
                'scope' => 'page-translation',
                'type' => 'translation',
            ],
            '',
            '',
            ['translate' => $model],
        );

        if ('Error' === $serverResult->getType()) {
            return new CallToolResult(
                [new TextContent('Server error: '.($serverResult->getResponseData()['message'] ?? 'unknown'))],
                isError: true,
            );
        }

        $this->backgroundTaskRepository->insertBackgroundTasks($bulkPayload);

        $allSkipped = array_merge($skipped, $failedPages);
        $totalPages = count($pageIds) - count($allSkipped);

        $text = sprintf("## Batch page translation started\n\n");
        $text .= sprintf("**Task ID:** `%s`\n", $parentUuid);
        $text .= sprintf("**Pages:** %d | **Scope:** %s | **Target:** %s\n", $totalPages, $translationScope, $targetLanguage);
        $text .= sprintf("**Model:** %s\n", $model);

        if (!empty($allSkipped)) {
            $text .= sprintf("\n⚠️ Skipped pages: %s (not found, excluded from AI, or no translatable content)\n", implode(', ', $allSkipped));
        }

        $text .= sprintf("\nProcessing happens in the background. Use **readTaskStatus(taskId: \"%s\")** to check progress.", $parentUuid);

        return $this->textResult($text);
    }
}
