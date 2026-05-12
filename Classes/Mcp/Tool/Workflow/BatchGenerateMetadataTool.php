<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Enumeration\CreditCostEnumeration;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuite\Service\WorkflowProcessingService;
use AutoDudes\AiSuiteMcp\Mcp\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\ContentFetchService;
use AutoDudes\AiSuiteMcp\Mcp\ToolDescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Batch-generate metadata for multiple pages using an external AI model.
 * Delegates to WorkflowProcessingService for payload building and background task creation.
 * Use getTaskStatus to check progress.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class BatchGenerateMetadataTool extends AbstractAiTool
{
    protected ?string $requiredScope = 'mcp:generate';

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly WorkflowProcessingService $workflowProcessingService,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly ContentFetchService $contentFetchService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'batchGenerateMetadata';
    }

    public function getDescription(): string
    {
        return 'Generate SEO metadata for multiple pages using an external AI model — costs credits per page. '
            .ToolDescriptionSnippets::BATCH_ASYNC_FLOW;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Array of page UIDs to generate metadata for.',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => ['seo_title', 'description'],
                    'description' => 'Metadata fields to generate: seo_title, description, og_title, og_description, twitter_title, twitter_description.',
                ],
                'model' => ['type' => 'string', 'description' => 'External AI model (e.g. ChatGPT). Omit to list available models.'],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
            ],
            'required' => ['pageIds'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageIds = $params['pageIds'] ?? [];
        $model = (string) ($params['model'] ?? '');
        $fields = $params['fields'] ?? ['seo_title', 'description'];
        $langIsoCode = $this->resolveLanguageIsoCode((string) ($params['language'] ?? ''), $pageIds[0] ?? 1);

        if (empty($pageIds)) {
            return new CallToolResult([new TextContent('pageIds must be a non-empty array.')], isError: true);
        }

        if ('' === $model) {
            $pageCount = count($pageIds);
            $text = sprintf("## Generate metadata for %d pages\n\n", $pageCount);

            $text .= "**Option 1 — Async (recommended for many pages):**\n";
            $text .= "  An external AI model processes all pages simultaneously in the background.\n";
            $text .= "  ⚡ You can continue working while the server generates — no waiting.\n";
            $text .= "  Page content is read automatically.\n\n";

            $text .= "**Option 2 — Sequential:**\n";
            $text .= "  You generate the metadata yourself, page by page.\n";
            $text .= "  ⏱ Takes longer — you must wait for each page to be processed.\n\n";

            $text .= "### Available models for Option 1:\n\n";

            $modelsResult = $this->listAvailableModels(
                $this->libraryService,
                GenerationLibraryEnumeration::METADATA,
                'createMetadata',
                ['text'],
                CreditCostEnumeration::METADATA,
                ['text' => 'AI models for metadata generation'],
            );

            foreach ($modelsResult->content as $content) {
                if ($content instanceof TextContent) {
                    $text .= $content->text;
                }
            }
            $text .= "\n\nPresent both options to the user and ask which approach they prefer.";

            return new CallToolResult([new TextContent($text)]);
        }

        $this->permissionService->validateModelAccess($model);

        // Filter valid pages and build pages map for WorkflowProcessingService.
        // validatePageForAi enforces PAGE_SHOW permission (skip-and-report) and the AI opt-out.
        $pages = [];
        $skipped = [];

        foreach ($pageIds as $pageId) {
            $pageId = (int) $pageId;

            try {
                $validated = $this->validatePageForAi($pageId, Permission::PAGE_SHOW);
            } catch (InsufficientPermissionException $e) {
                $this->logger->warning('BatchGenerateMetadata: skipping page — insufficient permission', [
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
            return new CallToolResult([new TextContent('No valid pages found to process.')], isError: true);
        }

        $parentUuid = $this->uuidService->generateUuid();
        $languageParts = [strtoupper($langIsoCode), (string) 0];

        // Use ContentFetchService as content fetcher (HTTP preview + DB fallback)
        $contentFetcher = function (int $pageUid, int $languageId): string {
            return $this->contentFetchService->fetchPageContent($pageUid, $languageId);
        };

        $allPayload = [];
        $allBulkPayload = [];
        $failedPages = [];

        foreach ($fields as $field) {
            $workflowData = [
                'parentUuid' => $parentUuid,
                'column' => $field,
                'textAiModel' => $model,
            ];

            $result = $this->workflowProcessingService->processPageMetadataGeneration(
                $workflowData,
                $pages,
                $languageParts,
                $contentFetcher,
            );

            array_push($allPayload, ...$result['payload']);
            array_push($allBulkPayload, ...$result['bulkPayload']);
            $failedPages = array_merge($failedPages, $result['failedPages']);
        }

        if (!empty($allPayload)) {
            $serverResult = $this->sendRequestService->sendDataRequest(
                'createMassAction',
                [
                    'uuid' => $parentUuid,
                    'payload' => $allPayload,
                    'scope' => 'page',
                    'type' => 'metadata',
                ],
                '',
                strtoupper($langIsoCode),
                ['text' => $model],
            );

            if ('Error' === $serverResult->getType()) {
                return new CallToolResult(
                    [new TextContent('Server error: '.($serverResult->getResponseData()['message'] ?? 'unknown'))],
                    isError: true,
                );
            }

            $this->backgroundTaskRepository->insertBackgroundTasks($allBulkPayload);
        }

        $allSkipped = array_merge($skipped, array_unique($failedPages));
        $totalPages = count($pageIds) - count($allSkipped);
        $totalTasks = count($allPayload);

        $text = sprintf("## Batch metadata generation started\n\n");
        $text .= sprintf("**Task ID:** `%s`\n", $parentUuid);
        $text .= sprintf("**Pages:** %d | **Fields per page:** %d | **Total tasks:** %d\n", $totalPages, count($fields), $totalTasks);
        $text .= sprintf("**Model:** %s\n", $model);

        if (!empty($allSkipped)) {
            $text .= sprintf("\n⚠️ Skipped pages: %s (not found or excluded from AI)\n", implode(', ', $allSkipped));
        }

        $text .= sprintf("\nProcessing happens in the background. Use **getTaskStatus(taskId: \"%s\")** to check progress.", $parentUuid);

        return new CallToolResult([new TextContent($text)]);
    }
}
