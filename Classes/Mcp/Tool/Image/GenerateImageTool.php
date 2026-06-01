<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Image;

use AutoDudes\AiSuite\Enumeration\CreditCostEnumeration;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\FileNameSanitizerService;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\Service\FilePreviewService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\Content;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Filesystem\Filesystem;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GenerateImageTool extends AbstractAiTool
{
    protected ?string $requiredScope = 'mcp:image';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly Filesystem $filesystem,
        private readonly FilePreviewService $filePreviewService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'generateImage';
    }

    public function getDescription(): string
    {
        return 'Generate an image using an external AI model (GPTImage, Midjourney, or Flux) — costs credits. '
            .'Writes the image directly to FAL file storage. Ask the user for confirmation before calling. '
            .'Returns the file UID of the created image plus a 256px preview thumbnail.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => ['type' => 'string', 'description' => 'Image description/prompt'],
                'model' => ['type' => 'string', 'description' => 'Image model identifier. Omit to get a list of available models first.'],
                'size' => [
                    'type' => 'string',
                    'enum' => ['256x256', '512x512', '1024x1024', '1024x1792', '1792x1024'],
                    'default' => '1024x1024',
                ],
                'targetFolder' => ['type' => 'string', 'default' => '1:/user_upload/', 'description' => 'FAL folder for the generated image'],
                'pageId' => ['type' => 'integer', 'description' => 'Page context for global instructions (optional)'],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
            ],
            'required' => ['prompt'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $model = (string) ($params['model'] ?? '');

        if ('' === $model) {
            return $this->listModels();
        }

        $this->permissionService->validateModelAccess($model);

        $pageId = (int) ($params['pageId'] ?? 0);
        $langIsoCode = $this->resolveLanguageIsoCode((string) ($params['language'] ?? ''), $pageId > 0 ? $pageId : 1);

        if ($pageId > 0) {
            $this->recordAccess->assertPagePerm($pageId, Permission::PAGE_SHOW);
            if ($this->isPageExcludedFromAi($pageId)) {
                return new CallToolResult(
                    [new TextContent($this->translateOrFallback('hint.page_excluded_from_ai', [$pageId], "Page {$pageId} excluded from AI processing."))],
                    isError: true,
                );
            }
        }

        $uuid = $this->uuidService->generateUuid();
        $targetFolder = (string) ($params['targetFolder'] ?? '1:/user_upload/');
        $prompt = (string) ($params['prompt'] ?? '');

        $this->recordAccess->assertFolderWriteAccess($targetFolder);

        if ($pageId > 0) {
            $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('pages', 'imageWizard', $pageId);
        } else {
            $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('files', 'imageWizard', null, $targetFolder);
        }

        // For Flux/GPTImage these URLs point to the final image; Midjourney would
        // additionally require a progress=finish step (not yet supported here).
        $result = $this->sendAiRequest('createImage', [
            'uuid' => $uuid,
            'progress' => 'prepare',
            'global_instructions' => $globalInstructions,
            'size' => $params['size'] ?? '1024x1024',
            'targetFolder' => $targetFolder,
        ], ['image' => $model], $langIsoCode, $prompt);

        $images = $result['images'] ?? [];
        $imageTitles = $result['imageTitles'] ?? [];
        if (!is_array($images) || [] === $images) {
            return $this->appendCreditInfo(
                new CallToolResult(
                    [new TextContent($this->appendDataFlowInfo('Image generation failed: AI model returned no images.', $model))],
                    isError: true,
                ),
                $result,
            );
        }

        if ('Midjourney' === $model) {
            return $this->appendCreditInfo(
                new CallToolResult(
                    [new TextContent('Midjourney requires an interactive selection step that is not yet supported via MCP. Please use the TYPO3 backend to generate Midjourney images, or choose Flux/GPTImage here.')],
                    isError: true,
                ),
                $result,
            );
        }

        $first = $images[0];
        $imageUrl = (string) (is_array($first) ? ($first['url'] ?? '') : $first);
        if ('' === $imageUrl) {
            return $this->appendCreditInfo(
                new CallToolResult(
                    [new TextContent($this->appendDataFlowInfo('Image generation failed: response contained no image URL.', $model))],
                    isError: true,
                ),
                $result,
            );
        }
        $imageTitle = (string) ($imageTitles[0] ?? '');

        try {
            $newFile = $this->storeImageInFolder($imageUrl, $imageTitle, $targetFolder);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist generated image to FAL', [
                'imageUrl' => $imageUrl,
                'targetFolder' => $targetFolder,
                'error' => $e->getMessage(),
            ]);

            return $this->appendCreditInfo(
                new CallToolResult(
                    [new TextContent($this->appendDataFlowInfo('Image was generated but could not be saved to file storage: '.$e->getMessage(), $model))],
                    isError: true,
                ),
                $result,
            );
        }

        $text = $this->appendDataFlowInfo('', $model);
        $text .= sprintf('Image generated and saved. File UID: %d (stored in %s).', $newFile->getUid(), $targetFolder);

        /** @var list<Content> $content */
        $content = [new TextContent($text)];
        $preview = $this->filePreviewService->generate($newFile, 256, 256);
        if (null !== $preview) {
            $content[] = $preview;
        }

        return $this->appendCreditInfo(new CallToolResult($content), $result);
    }

    private function listModels(): CallToolResult
    {
        return $this->listAvailableModels(
            $this->libraryService,
            GenerationLibraryEnumeration::IMAGE,
            'createImage',
            ['image'],
            CreditCostEnumeration::IMAGE,
            ['image' => 'Image generation models'],
        );
    }

    private function storeImageInFolder(string $imageUrl, string $imageTitle, string $targetFolder): File
    {
        $folder = $this->resolveTargetFolder($targetFolder);

        $urlExtension = pathinfo($imageUrl, PATHINFO_EXTENSION);
        $urlExtension = '' !== $urlExtension ? strtolower($urlExtension) : 'png';

        $baseName = '' !== trim($imageTitle) ? $imageTitle : 'ai-generated-image-'.time();
        $baseName = FileNameSanitizerService::sanitize($baseName);

        $tempBase = GeneralUtility::tempnam('ai_image_');
        $this->filesystem->copy($imageUrl, $tempBase);

        if (!file_exists($tempBase) || 0 === filesize($tempBase)) {
            @unlink($tempBase);

            throw new \RuntimeException(sprintf('Failed to download image from %s', $imageUrl));
        }

        $detectedMime = mime_content_type($tempBase);
        $realExtension = match ($detectedMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => $urlExtension,
        };

        $tempFile = $tempBase.'.'.$realExtension;
        rename($tempBase, $tempFile);

        $targetFileName = $baseName.'.'.$realExtension;
        if ($folder->hasFile($targetFileName)) {
            $targetFileName = $baseName.'-'.time().'.'.$realExtension;
        }

        try {
            $newFile = $folder->getStorage()->addFile($tempFile, $folder, $targetFileName);
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        if (!$newFile instanceof File) {
            throw new \RuntimeException('Storing image returned an unexpected file type.');
        }

        if ('' !== trim($imageTitle)) {
            $metaData = $newFile->getMetaData();
            $metaData->offsetSet('title', $imageTitle);
            $metaData->offsetSet('alternative', $imageTitle);
            $metaData->save();
        }

        return $newFile;
    }

    private function resolveTargetFolder(string $targetFolder): Folder
    {
        $combined = $targetFolder;
        if (!preg_match('/^\d+:/', $combined)) {
            $combined = '1:'.$combined;
        }
        [$storagePrefix, $folderPath] = explode(':', $combined, 2);
        $folderPath = rtrim($folderPath, '/').'/';
        $combined = $storagePrefix.':'.$folderPath;

        return $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combined);
    }
}
