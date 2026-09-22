<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuite\Service\WorkflowProcessingService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Service\ContentFetchService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow\Trait\PageSubtreeSelectionTrait;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class BatchGenerateMetadataTool extends AbstractAiTool
{
    use PageSubtreeSelectionTrait;

    protected ?string $requiredScope = 'mcp:workflow';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly WorkflowProcessingService $workflowProcessingService,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly ContentFetchService $contentFetchService,
        private readonly PagesRepository $pagesRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'batchGenerateMetadata';
    }

    public function getDescription(): string
    {
        return 'Bulk-generates SEO metadata for many pages at once with an external AI model (costs credits). '
            .'Takes either a UID list or a whole page subtree via rootPageId. '
            .'Sized for many pages, not for a single one. '
            .DescriptionSnippets::BATCH_ASYNC;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::pageSelectionSchemaProperties('generate metadata for') + [
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => ['seo_title', 'description'],
                    'description' => 'Metadata fields to generate: seo_title, description, og_title, og_description, twitter_title, twitter_description, abstract.',
                ],
                'model' => ['type' => 'string', 'description' => 'External AI model (e.g. ChatGPT). Omit to list available models.'],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
                'prompt' => ['type' => 'string', 'description' => 'Own instruction for the generation. Replaces the predefined instruction of the field, so it has to state the wanted length and style itself. Omit to keep the predefined one.'],
            ],
            'required' => [],
        ];
    }

    protected function validatePermissions(): void
    {
        parent::validatePermissions();
        $this->permissionService->validateFeatureScope('mcp:generate');
    }

    protected function doExecute(array $params): CallToolResult
    {
        $model = (string) ($params['model'] ?? '');
        $fields = $params['fields'] ?? ['seo_title', 'description'];

        $pageIds = $this->resolveTargetPages($params);
        if ($pageIds instanceof CallToolResult) {
            return $pageIds;
        }

        $langIsoCode = $this->resolveLanguageIsoCode((string) ($params['language'] ?? ''), $pageIds[0] ?? 1);

        if ('' === $model) {
            $pageCount = count($pageIds);
            $text = sprintf("## Generate metadata for %s\n\n", $this->outputFormatter->countOf($pageCount, 'page'));

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
                ['text' => 'AI models for metadata generation'],
            );

            foreach ($modelsResult->content as $content) {
                if ($content instanceof TextContent) {
                    $text .= $content->text;
                }
            }
            $text .= "\n\nPick Option 1 unless the caller asked to wait for the result, name the model you chose in one line, and call this tool again with it.";

            return $this->textResult($text);
        }

        $this->permissionService->validateModelAccess($model);

        $pages = [];
        $skipped = [];

        foreach ($pageIds as $pageId) {
            $pageId = (int) $pageId;

            try {
                $validated = $this->validatePageForAi($pageId);
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
            return $this->textError('No valid pages found to process.');
        }

        $parentUuid = $this->uuidService->generateUuid();
        $languageParts = [strtoupper($langIsoCode), (string) 0];

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
                'customPrompt' => trim((string) ($params['prompt'] ?? '')),
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

        $text .= sprintf(
            "\nProcessing happens in the background, and nothing is written yet. Poll "
            .'**readTaskStatus(taskId: "%s")**; once it reports finished, read the suggestions with '
            .'**readTaskResults(taskId: "%s")** and persist the ones you keep with **writeRecords**.',
            $parentUuid,
            $parentUuid,
        );

        return $this->textResult($text);
    }
}
