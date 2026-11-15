<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Domain\Repository\SysFileMetadataRepository;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\GlossarService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuite\Service\WorkflowProcessingService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow\Trait\FolderFileSelectionTrait;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class BatchTranslateFileMetadataTool extends AbstractAiTool
{
    use FolderFileSelectionTrait;

    protected ?string $requiredScope = 'mcp:workflow';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly SysFileMetadataRepository $sysFileMetadataRepository,
        private readonly WorkflowProcessingService $workflowProcessingService,
        private readonly GlossarService $glossarService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'batchTranslateFileMetadata';
    }

    public function getDescription(): string
    {
        return 'Translate file metadata (alt text, title, description) with an external AI model (costs credits), '
            .'for files given by UID or for every file in the given FAL folders. '
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
                    'description' => 'sys_file UIDs to translate metadata for. Alternative to folderIdentifiers; give one of the two.',
                ],
                'folderIdentifiers' => self::folderIdentifiersSchemaProperty(),
                'targetLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO target language code (de, en, fr, es, ...).',
                ]),
                'sourceLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO source language. Default: site default language.',
                ]),
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => ['alternative', 'title', 'description'],
                    'description' => 'Metadata fields to translate: alternative (alt text), title, description.',
                ],
                'model' => ['type' => 'string', 'description' => 'Translation model identifier (e.g. DeepL). Omit to list available models.'],
                'pageId' => ['type' => 'integer', 'description' => 'Optional. UID of any page of the site whose glossary applies; files belong to no site, so without it no glossary is used.'],
            ],
            'required' => ['targetLanguage'],
        ];
    }

    protected function validatePermissions(): void
    {
        parent::validatePermissions();
        $this->permissionService->validateFeatureScope('mcp:translate');
    }

    protected function doExecute(array $params): CallToolResult
    {
        $params = $this->withFilesFromFolders($params);
        if ($params instanceof CallToolResult) {
            return $params;
        }

        $fileUids = $params['fileUids'] ?? [];
        $model = (string) ($params['model'] ?? '');
        $targetLanguage = (string) $params['targetLanguage'];
        $fields = $params['fields'] ?? ['alternative', 'title', 'description'];

        if (empty($fileUids)) {
            return $this->textError('Give fileUids or folderIdentifiers — one of the two, non-empty.');
        }

        if ('' === $model) {
            $fileCount = count($fileUids);
            $text = sprintf("## Translate file metadata for %s to %s\n\n", $this->outputFormatter->countOf($fileCount, 'file'), $targetLanguage);

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
                ['text' => 'Translation models'],
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

        $glossaryPageId = max(0, (int) ($params['pageId'] ?? 0));
        if ($glossaryPageId > 0) {
            $this->recordAccess->assertPagePerm($glossaryPageId, Permission::PAGE_SHOW);
        }
        $sourceLanguage = $params['sourceLanguage'] ?? '';
        $languagePageId = $this->referencePageId($glossaryPageId, $sourceLanguage, $targetLanguage);
        if (0 === $languagePageId) {
            return $this->textError('No site in your web mounts to resolve the languages from. Pass pageId with a page of the site whose languages apply.');
        }

        if ('' === $sourceLanguage) {
            $sourceLanguage = $this->resolveLanguageIsoCode('', $languagePageId);
        }

        $sourceLanguageUid = $this->recordAccess->resolveLanguageUid($sourceLanguage, $languagePageId);
        $targetLanguageUid = $this->recordAccess->resolveLanguageUid($targetLanguage, $languagePageId);

        if (0 === $targetLanguageUid) {
            return $this->textError("Language \"{$targetLanguage}\" is not configured for this site.");
        }

        $this->recordAccess->assertLanguageAccess($targetLanguageUid);
        $column = count($fields) > 1 ? 'all' : ($fields[0] ?? 'alternative');

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
                $file = $this->recordAccess->assertFileReadAccess($fileUid);
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
            return $this->textError('No valid files found to translate.');
        }

        $this->assertCreditBudget();
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
            return $this->textError('No translatable content found for the given files.');
        }

        $serverResult = $this->sendRequestService->sendDataRequest(
            'createMassAction',
            [
                'uuid' => $parentUuid,
                'payload' => $payload,
                'scope' => 'metadata',
                'type' => 'translation',
                ...$this->glossarService->buildRequestParameters(
                    (string) json_encode($result['translatableContentForGlossary'], SendRequestService::JSON_SAFE_FLAGS),
                    $sourceLanguageUid,
                    $targetLanguageUid,
                    $this->glossarService->getSiteRootPageId($glossaryPageId),
                ),
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

        $text .= sprintf(
            "\nProcessing happens in the background, and nothing is written yet. When the "
            .'user wants the result now, check **readTaskStatus(taskId: "%s")** once; when it reports finished, **applyTaskResults(taskId: "%s")** '
            .'writes the translations.',
            $parentUuid,
            $parentUuid,
        );

        return $this->textResult($text);
    }
}
