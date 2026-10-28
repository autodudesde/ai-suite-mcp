<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Media;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\FetchedMedia;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\MediaUploadPlan;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\StoredMedia;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\RefusedRequestException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\FilePreviewService;
use AutoDudes\AiSuiteMcp\Mcp\Service\MediaUploadPreflight;
use AutoDudes\AiSuiteMcp\Mcp\Service\MediaUploadSettings;
use AutoDudes\AiSuiteMcp\Mcp\Service\RemoteMediaService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\Content;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

class UploadMediaTool extends AbstractTool
{
    private const PREVIEW_LIMIT = 5;
    protected ?string $requiredScope = 'mcp:media';
    protected bool $openWorldHint = true;
    // FAL writes bypass versioningWS, so no write mode undoes this.
    protected bool $destructiveHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly RemoteMediaService $remoteMediaService,
        private readonly FilePreviewService $filePreviewService,
        private readonly BatchResultBuilderService $batchResultBuilder,
        private readonly MediaUploadSettings $mediaUploadSettings,
        private readonly MediaUploadPreflight $preflight,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'uploadMedia';
    }

    public function getDescription(): string
    {
        return 'Bring one or more existing images/videos into the file storage (writes). Not an AI tool: it uploads '
            .'media you already have, by remote URL, inline base64, or a YouTube/Vimeo link. See the schema for the '
            .'per-item sources and optional metadata.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'media' => [
                    'type' => 'array',
                    'description' => 'The media items to bring into FAL. Each: {url?, content?, fileName?, targetFolder?, onConflict?, createFolder?, title?, alternative?, description?}. Exactly one source per item: `url` for a remote http(s) file (downloaded), `content` for base64 / a data-URI (direct upload, needs fileName), or a YouTube/Vimeo link in `url` (stored as an online-media reference, not downloaded). Never give both url and content. Prefer url or an online-media link for videos — base64 is impractical for large files.',
                    'items' => ['type' => 'object'],
                ],
                'targetFolder' => [
                    'type' => 'string',
                    'description' => 'Default FAL folder (combined identifier, e.g. "1:/user_upload/") for all items without their own targetFolder.',
                ],
                'onConflict' => [
                    'type' => 'string',
                    'enum' => ['rename', 'replace'],
                    'default' => 'rename',
                    'description' => 'When the file name already exists in the target folder: "rename" stores it under a suffixed name, "replace" overwrites the existing file\'s content in place (same uid, every reference shows the new file). Default for all items without their own onConflict.',
                ],
                'createFolder' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Create a missing target folder (and missing parents) instead of failing. Default for all items without their own createFolder.',
                ],
            ],
            'required' => ['media'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $media = $params['media'] ?? null;
        if (!is_array($media) || [] === $media) {
            return $this->textError('media must be a non-empty array.');
        }

        $config = $this->mediaUploadSettings->read();
        $batchFolder = '' !== (string) ($params['targetFolder'] ?? '')
            ? (string) $params['targetFolder']
            : $config['defaultFolder'];
        $batchConflictMode = $this->preflight->resolveConflictMode($params['onConflict'] ?? 'rename');
        $batchCreateFolder = (bool) ($params['createFolder'] ?? false);

        /** @var list<Content> $previews */
        $previews = [];

        $outcome = $this->batchResultBuilder->build($media, 'media item(s)', function (mixed $item) use (&$previews, $batchFolder, $batchConflictMode, $batchCreateFolder, $config): array {
            $plan = $this->preflight->plan($item, $batchFolder, $batchConflictMode, $batchCreateFolder);
            $stored = $this->processItem($plan, (array) $item, $config);
            $file = $stored->file;

            if (count($previews) < self::PREVIEW_LIMIT && str_starts_with((string) $file->getMimeType(), 'image/')) {
                $preview = $this->filePreviewService->generate($file, 256, 256);
                if (null !== $preview) {
                    $previews[] = $preview;
                }
            }

            $metadataUid = (int) ($file->getMetaData()->get()['uid'] ?? 0);

            return [
                'message' => sprintf('%s %s (UID: %d) in %s', $file->getName(), $stored->outcome, $file->getUid(), $file->getParentFolder()->getCombinedIdentifier()),
                'uid' => $file->getUid(),
            ] + ($metadataUid > 0 ? ['table' => 'sys_file_metadata', 'recordUid' => $metadataUid, 'action' => 'create'] : []);
        }, workspaceContained: false);

        return new CallToolResult(
            [new TextContent($outcome->text), ...$previews],
            isError: $outcome->hadError(),
            structuredContent: $this->batchResultBuilder->structuredFor($outcome),
        );
    }

    /**
     * @param array<string, mixed>                                                                                                          $item
     * @param array{defaultFolder: string, maxBytes: int, allowedExtensions: list<string>, allowUrlFetch: bool, hostDenylist: list<string>} $config
     */
    private function processItem(MediaUploadPlan $plan, array $item, array $config): StoredMedia
    {
        $folder = $this->preflight->createMissingFolders($plan->writableFolder, $plan->missingFolders);

        if ('' !== $plan->url) {
            $onlineMedia = $this->remoteMediaService->transformOnlineMediaUrl($plan->url, $folder);
            $stored = $onlineMedia instanceof File ? new StoredMedia($onlineMedia, StoredMedia::CREATED) : null;
            if (!$stored instanceof StoredMedia) {
                if (!$config['allowUrlFetch']) {
                    throw new InsufficientPermissionException('Downloading media from remote URLs is disabled by configuration (only base64 upload and online-media links are allowed).');
                }
                $stored = $this->storeFetched(
                    $this->remoteMediaService->fetch($plan->url, $config['maxBytes'], $config['hostDenylist']),
                    $folder,
                    $plan->url,
                    $item,
                    $plan->replace,
                    $config,
                );
            }
        } else {
            $stored = $this->storeFetched(
                $this->remoteMediaService->decodeBase64ToTempFile($plan->content, $config['maxBytes']),
                $folder,
                '',
                $item,
                $plan->replace,
                $config,
            );
        }

        $this->remoteMediaService->applyMetadata($stored->file, [
            'title' => trim((string) ($item['title'] ?? '')),
            'alternative' => trim((string) ($item['alternative'] ?? '')),
            'description' => trim((string) ($item['description'] ?? '')),
        ]);

        return $stored;
    }

    /**
     * @param array<string, mixed>                                                                                                          $item
     * @param array{defaultFolder: string, maxBytes: int, allowedExtensions: list<string>, allowUrlFetch: bool, hostDenylist: list<string>} $config
     */
    private function storeFetched(FetchedMedia $fetched, Folder $folder, string $url, array $item, bool $replace, array $config): StoredMedia
    {
        try {
            $extension = $this->remoteMediaService->resolveExtension($fetched->mimeType, $url, (string) ($item['fileName'] ?? ''));
            $this->assertExtensionAllowed($extension, $config['allowedExtensions']);
            $baseName = $this->remoteMediaService->resolveBaseName(
                (string) ($item['fileName'] ?? ''),
                $url,
                (string) ($item['title'] ?? ''),
            );

            return $this->remoteMediaService->storeTempFile($folder, $fetched->tempFilePath, $baseName, $extension, $replace);
        } finally {
            if (is_file($fetched->tempFilePath)) {
                @unlink($fetched->tempFilePath);
            }
        }
    }

    /**
     * @param list<string> $allowed
     */
    private function assertExtensionAllowed(string $extension, array $allowed): void
    {
        if (!in_array($extension, $allowed, true)) {
            throw RefusedRequestException::invalidParameter(sprintf('File type ".%s" is not allowed. Allowed: %s.', $extension, implode(', ', $allowed)));
        }
    }
}
