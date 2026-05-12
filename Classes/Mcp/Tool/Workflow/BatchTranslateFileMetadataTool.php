<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Domain\Repository\SysFileMetadataRepository;
use AutoDudes\AiSuite\Enumeration\CreditCostEnumeration;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuite\Service\WorkflowProcessingService;
use AutoDudes\AiSuiteMcp\Mcp\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use AutoDudes\AiSuiteMcp\Mcp\ToolDescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Resource\File;

/**
 * Batch-translate file metadata (alt text, title, description) for multiple files.
 * Delegates to WorkflowProcessingService for payload building and background task creation.
 * Use getTaskStatus to check progress.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class BatchTranslateFileMetadataTool extends AbstractAiTool
{
    protected ?string $requiredScope = 'mcp:translate';

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly SysFileMetadataRepository $sysFileMetadataRepository,
        private readonly WorkflowProcessingService $workflowProcessingService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'batchTranslateFileMetadata';
    }

    public function getDescription(): string
    {
        return 'Translate file metadata (alt text, title, description) for specific files using an external AI model — costs credits per file. '
            .'For processing all files in a folder, use batchTranslateFolderMetadata instead. '
            .ToolDescriptionSnippets::BATCH_ASYNC_FLOW;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileUids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Array of sys_file UIDs to translate metadata for.',
                ],
                'targetLanguage' => ['type' => 'string', 'description' => 'ISO target language code (de, en, fr, es, ...).'],
                'sourceLanguage' => ['type' => 'string', 'description' => 'ISO source language. Default: site default language.'],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => ['alternative', 'title', 'description'],
                    'description' => 'Metadata fields to translate: alternative (alt text), title, description.',
                ],
                'model' => ['type' => 'string', 'description' => 'Translation model identifier (e.g. DeepL). Omit to list available models.'],
            ],
            'required' => ['fileUids', 'targetLanguage'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $fileUids = $params['fileUids'] ?? [];
        $model = (string) ($params['model'] ?? '');
        $targetLanguage = (string) $params['targetLanguage'];
        $fields = $params['fields'] ?? ['alternative', 'title', 'description'];

        if (empty($fileUids)) {
            return new CallToolResult([new TextContent('fileUids must be a non-empty array.')], isError: true);
        }

        if ('' === $model) {
            $fileCount = count($fileUids);
            $text = sprintf("## Translate file metadata for %d files to %s\n\n", $fileCount, $targetLanguage);

            $text .= "**Option 1 — Async (recommended):**\n";
            $text .= "  An external AI model translates all file metadata simultaneously in the background.\n";
            $text .= "  ⚡ You can continue working while the server translates — no waiting.\n\n";

            $text .= "**Option 2 — Sequential:**\n";
            $text .= "  You translate each file individually using translateFileMetadata.\n";
            $text .= "  ⏱ Takes longer — you must process each file one by one.\n\n";

            $text .= "### Available models for Option 1:\n\n";

            $modelsResult = $this->listAvailableModels(
                $this->libraryService,
                GenerationLibraryEnumeration::TRANSLATE,
                'translate',
                ['text'],
                CreditCostEnumeration::TRANSLATION,
                ['text' => 'Translation models'],
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

        $sourceLanguage = $params['sourceLanguage'] ?? '';
        if ('' === $sourceLanguage) {
            $sourceLanguage = $this->resolveLanguageIsoCode('', 1);
        }

        $sourceLanguageUid = $this->resolveLanguageUid($sourceLanguage, 1);
        $targetLanguageUid = $this->resolveLanguageUid($targetLanguage, 1);
        $this->assertLanguageAccess($targetLanguageUid);
        $column = count($fields) > 1 ? 'all' : ($fields[0] ?? 'alternative');

        // Resolve source metadata and build files map for WorkflowProcessingService
        $sourceMetadataList = $this->sysFileMetadataRepository->findByLangUidAndFileIdList(
            $fileUids,
            $column,
            'file',
            $sourceLanguageUid,
        );

        $targetMetadataList = $this->sysFileMetadataRepository->findByLangUidAndFileIdList(
            $fileUids,
            $column,
            'file',
            $targetLanguageUid,
        );

        $defaultLanguageMetadataUids = $this->sysFileMetadataRepository->findDefaultLanguageMetadataUidsByFileUids($fileUids);

        $files = [];
        $metadataListFromRepo = [];
        $skipped = [];

        foreach ($fileUids as $fileUid) {
            $fileUid = (int) $fileUid;

            try {
                $file = $this->assertFileReadAccess($fileUid);
            } catch (InsufficientPermissionException $e) {
                $this->logger->warning('BatchTranslateFileMetadata: skipping file — insufficient permission', [
                    'fileUid' => $fileUid,
                    'reason' => $e->getMessage(),
                ]);
                $skipped[] = $fileUid;

                continue;
            } catch (\RuntimeException $e) {
                $this->logger->warning('BatchTranslateFileMetadata: skipping file — file not found', [
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

                $sourceMetadata = $sourceMetadataList[$fileUid] ?? null;
                if (null === $sourceMetadata) {
                    $skipped[] = $fileUid;

                    continue;
                }

                $targetMetadata = $targetMetadataList[$fileUid] ?? null;
                $mode = '';

                if (null === $targetMetadata && $targetLanguageUid > 0) {
                    $defaultMetadataUid = $defaultLanguageMetadataUids[$fileUid] ?? 0;
                    if ($defaultMetadataUid > 0) {
                        $targetMetadata = [
                            'uid' => $defaultMetadataUid,
                            'file' => $fileUid,
                        ];
                        $mode = 'NEW';
                    }
                }

                if (null === $targetMetadata) {
                    $skipped[] = $fileUid;

                    continue;
                }

                $sysFileMetaUid = (int) $targetMetadata['uid'];
                $fieldsToTranslate = 'all' === $column ? $fields : [$column];

                foreach ($fieldsToTranslate as $field) {
                    $sourceValue = $sourceMetadata[$field] ?? '';
                    if ('' === $sourceValue) {
                        continue;
                    }
                    $files[$sysFileMetaUid][$field] = $sourceValue;
                }

                if (!empty($files[$sysFileMetaUid])) {
                    $files[$sysFileMetaUid]['mode'] = $mode;
                    $metadataListFromRepo[$sysFileMetaUid] = ['file' => $fileUid];
                }
            } catch (\Throwable $e) {
                $this->logger->error('BatchTranslateFileMetadata: skipping file', ['fileUid' => $fileUid, 'error' => $e->getMessage()]);
                $skipped[] = $fileUid;
            }
        }

        if (empty($files)) {
            return new CallToolResult([new TextContent('No valid files found to translate.')], isError: true);
        }

        $parentUuid = $this->uuidService->generateUuid();

        $result = $this->workflowProcessingService->processFileMetadataTranslation(
            $files,
            $metadataListFromRepo,
            $parentUuid,
            strtoupper($sourceLanguage),
            strtoupper($targetLanguage),
            $targetLanguageUid,
            model: $model,
        );

        $payload = $result['payload'];
        $bulkPayload = $result['bulkPayload'];
        $failedFilesMetadata = $result['failedFilesMetadata'];

        if (empty($payload)) {
            return new CallToolResult([new TextContent('No translatable content found for the given files.')], isError: true);
        }

        $serverResult = $this->sendRequestService->sendDataRequest(
            'createMassAction',
            [
                'uuid' => $parentUuid,
                'payload' => $payload,
                'scope' => 'metadata',
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

        $allSkipped = array_merge($skipped, $failedFilesMetadata);
        $totalFiles = count($fileUids) - count($allSkipped);

        $text = sprintf("## Batch file metadata translation started\n\n");
        $text .= sprintf("**Task ID:** `%s`\n", $parentUuid);
        $text .= sprintf("**Files:** %d | **Target:** %s | **Model:** %s\n", $totalFiles, $targetLanguage, $model);

        if (!empty($allSkipped)) {
            $text .= sprintf("\n⚠️ Skipped files: %s (not found, no source metadata, or not accessible)\n", implode(', ', $allSkipped));
        }

        $text .= sprintf("\nProcessing happens in the background. Use **getTaskStatus(taskId: \"%s\")** to check progress.", $parentUuid);

        return new CallToolResult([new TextContent($text)]);
    }
}
