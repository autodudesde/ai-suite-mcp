<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\MetadataService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuite\Service\WorkflowProcessingService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Resource\File;

#[AutoconfigureTag('aisuite.mcp.tool')]
class BatchGenerateFileMetadataTool extends AbstractAiTool
{
    // Gating scope = the mass-action scope the gate actually checks (TOOL_SCOPE_MAP).
    // The AI feature permission is verified on top, in validatePermissions().
    protected ?string $requiredScope = 'mcp:workflow';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly WorkflowProcessingService $workflowProcessingService,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'batchGenerateFileMetadata';
    }

    public function getDescription(): string
    {
        return 'Generate file metadata (alt text, title, description) for specific files with an external AI model (costs credits). '
            .'For processing all files in a folder, use batchGenerateFolderMetadata instead. '
            .DescriptionSnippets::BATCH_ASYNC;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileUids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Array of sys_file UIDs to generate metadata for.',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => ['alternative', 'title'],
                    'description' => 'Metadata fields to generate: alternative (alt text), title, description.',
                ],
                'model' => ['type' => 'string', 'description' => 'AI model identifier (e.g. Vision). Omit to list available models.'],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
            ],
            'required' => ['fileUids'],
        ];
    }

    protected function validatePermissions(): void
    {
        parent::validatePermissions();
        $this->permissionService->validateFeatureScope('mcp:generate');
    }

    protected function doExecute(array $params): CallToolResult
    {
        $fileUids = $params['fileUids'] ?? [];
        $model = (string) ($params['model'] ?? '');
        $fields = $params['fields'] ?? ['alternative', 'title'];
        $langIsoCode = $this->resolveLanguageIsoCode((string) ($params['language'] ?? ''), 1);

        if (empty($fileUids)) {
            return $this->textError('fileUids must be a non-empty array.');
        }

        if ('' === $model) {
            $fileCount = count($fileUids);
            $text = sprintf("## Generate file metadata for %d files\n\n", $fileCount);

            $text .= "**Option 1 — Async (recommended):**\n";
            $text .= "  An external AI model (e.g. Vision) processes all files simultaneously in the background.\n";
            $text .= "  ⚡ You can continue working while the server generates — no waiting.\n\n";

            $text .= "**Option 2 — Sequential:**\n";
            $text .= "  You describe each image yourself and write metadata manually.\n";
            $text .= "  ⏱ Takes longer — you must process each file one by one.\n\n";

            $text .= "### Available models for Option 1:\n\n";

            $modelsResult = $this->listAvailableModels(
                $this->libraryService,
                GenerationLibraryEnumeration::METADATA,
                'createMetadata',
                ['text'],
                ['text' => 'AI models for file metadata generation'],
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

        // Resolve sys_file UIDs → sys_file_metadata UIDs
        $workflowDataFiles = [];
        $skipped = [];

        foreach ($fileUids as $fileUid) {
            $fileUid = (int) $fileUid;

            try {
                // Filemount-aware permission check (skip-and-report).
                $file = $this->recordAccess->assertFileReadAccess($fileUid);
            } catch (InsufficientPermissionException $e) {
                $this->logger->warning('BatchGenerateFileMetadata: skipping file — insufficient permission', [
                    'fileUid' => $fileUid,
                    'reason' => $e->getMessage(),
                ]);
                $skipped[] = $fileUid;

                continue;
            } catch (\RuntimeException $e) {
                $this->logger->warning('BatchGenerateFileMetadata: skipping file — file not found', [
                    'fileUid' => $fileUid,
                    'reason' => $e->getMessage(),
                ]);
                $skipped[] = $fileUid;

                continue;
            }

            try {
                if (!$file instanceof File) {
                    $skipped[] = $fileUid;

                    continue;
                }

                if (!\in_array($file->getMimeType(), MetadataService::SUPPORTED_IMAGE_MIME_TYPES, true)) {
                    $skipped[] = $fileUid;

                    continue;
                }

                $metaData = $file->getMetaData()->get();
                $fileMetadataUid = (int) ($metaData['uid'] ?? 0);
                if (0 === $fileMetadataUid) {
                    $skipped[] = $fileUid;

                    continue;
                }

                $entry = ['mode' => ''];
                foreach ($fields as $field) {
                    $entry[(string) $field] = '';
                }
                $workflowDataFiles[$fileMetadataUid] = $entry;
            } catch (\Throwable $e) {
                $this->logger->error('BatchGenerateFileMetadata: skipping file', ['fileUid' => $fileUid, 'error' => $e->getMessage()]);
                $skipped[] = $fileUid;
            }
        }

        if (empty($workflowDataFiles)) {
            return $this->textError('No valid files found to process.');
        }

        $parentUuid = $this->uuidService->generateUuid();
        $languageParts = [strtoupper($langIsoCode), (string) 0];

        $allFailedFiles = [];

        foreach ($fields as $field) {
            $workflowData = [
                'parentUuid' => $parentUuid,
                'column' => $field,
                'textAiModel' => $model,
            ];

            $result = $this->workflowProcessingService->processFilelistFilesForMetadataGeneration(
                $workflowData,
                $workflowDataFiles,
                $languageParts,
                'fileMetadata',
                $this->sendRequestService,
            );

            $remainingPayload = $result['payload'] ?? [];
            $remainingBulkPayload = $result['bulkPayload'] ?? [];

            if (!empty($remainingPayload)) {
                $serverResult = $this->sendRequestService->sendDataRequest(
                    'createMassAction',
                    [
                        'uuid' => $parentUuid,
                        'payload' => $remainingPayload,
                        'scope' => 'fileMetadata',
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

                $this->backgroundTaskRepository->insertBackgroundTasks($remainingBulkPayload);
            }

            $allFailedFiles = array_merge($allFailedFiles, $result['failedFilesMetadata'] ?? []);
        }

        $failedFiles = array_unique($allFailedFiles);
        $allSkipped = array_merge($skipped, $failedFiles);
        $totalFiles = count($fileUids) - count($allSkipped);

        $text = sprintf("## Batch file metadata generation started\n\n");
        $text .= sprintf("**Task ID:** `%s`\n", $parentUuid);
        $text .= sprintf("**Files:** %d | **Model:** %s\n", $totalFiles, $model);

        if (!empty($allSkipped)) {
            $text .= sprintf("\n⚠️ Skipped files: %s (not found or not accessible)\n", implode(', ', $allSkipped));
        }

        $text .= sprintf("\nProcessing happens in the background. Use **readTaskStatus(taskId: \"%s\")** to check progress.", $parentUuid);

        return $this->textResult($text);
    }
}
