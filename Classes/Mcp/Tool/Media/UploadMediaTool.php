<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Media;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\FetchedMedia;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\FilePreviewService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RemoteMediaService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\Content;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

#[AutoconfigureTag('aisuite.mcp.tool')]
class UploadMediaTool extends AbstractTool
{
    private const PREVIEW_LIMIT = 5;
    protected ?string $requiredScope = 'mcp:media';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly RemoteMediaService $remoteMediaService,
        private readonly FilePreviewService $filePreviewService,
        private readonly BatchResultBuilderService $batchResultBuilder,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'uploadMedia';
    }

    public function getDescription(): string
    {
        return 'Upload one or more images/videos into the file storage (FAL). '
            .'Always pass a "media" array. Each item provides exactly one source: '
            .'"url" (remote http(s) file — downloaded), "content" (base64 / data-URI — direct upload), '
            .'or a YouTube/Vimeo link in "url" (stored as an online-media reference, not downloaded). '
            .'Videos are best provided via url or online-media link — base64 is impractical for large files. '
            .'Optionally set fileName, targetFolder and metadata (title, alternative, description).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'media' => [
                    'type' => 'array',
                    'description' => 'Array of media items. Each: {url? , content?, fileName?, targetFolder?, title?, alternative?, description?}. '
                        .'Provide either url OR content. fileName is required for base64 content without a derivable name.',
                    'items' => ['type' => 'object'],
                ],
                'targetFolder' => [
                    'type' => 'string',
                    'description' => 'Default FAL folder (combined identifier, e.g. "1:/user_upload/") for all items without their own targetFolder.',
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

        $config = $this->getMediaConfig();
        $batchFolder = '' !== (string) ($params['targetFolder'] ?? '')
            ? (string) $params['targetFolder']
            : $config['defaultFolder'];

        /** @var list<Content> $previews */
        $previews = [];

        $text = $this->batchResultBuilder->renderText($media, 'media item(s)', function (mixed $item) use (&$previews, $batchFolder, $config): array {
            if (!is_array($item)) {
                throw new InvalidParameterException('Skipped (not an object).');
            }

            $file = $this->processItem($item, $batchFolder, $config);

            if (count($previews) < self::PREVIEW_LIMIT && str_starts_with((string) $file->getMimeType(), 'image/')) {
                $preview = $this->filePreviewService->generate($file, 256, 256);
                if (null !== $preview) {
                    $previews[] = $preview;
                }
            }

            return [
                'message' => sprintf('%s stored (UID: %d) in %s', $file->getName(), $file->getUid(), $file->getParentFolder()->getCombinedIdentifier()),
                'uid' => $file->getUid(),
            ];
        });

        return new CallToolResult([new TextContent($text), ...$previews]);
    }

    /**
     * @param array<string, mixed>                                                                                                          $item
     * @param array{defaultFolder: string, maxBytes: int, allowedExtensions: list<string>, allowUrlFetch: bool, hostDenylist: list<string>} $config
     */
    private function processItem(array $item, string $batchFolder, array $config): File
    {
        $targetFolder = '' !== (string) ($item['targetFolder'] ?? '')
            ? (string) $item['targetFolder']
            : $batchFolder;

        $folder = $this->recordAccess->assertFolderWriteAccess(
            $this->remoteMediaService->normalizeFolderIdentifier($targetFolder),
        );

        $url = trim((string) ($item['url'] ?? ''));
        $content = (string) ($item['content'] ?? '');

        if ('' !== $url && '' !== $content) {
            throw new \RuntimeException('Provide either url or content, not both.');
        }

        if ('' !== $url) {
            // Online media (YouTube/Vimeo/…) is stored as a reference, not downloaded.
            $file = $this->remoteMediaService->transformOnlineMediaUrl($url, $folder);
            if (!$file instanceof File) {
                if (!$config['allowUrlFetch']) {
                    throw new \RuntimeException('Downloading media from remote URLs is disabled by configuration (only base64 upload and online-media links are allowed).');
                }
                $file = $this->storeFetched(
                    $this->remoteMediaService->fetch($url, $config['maxBytes'], $config['hostDenylist']),
                    $folder,
                    $url,
                    $item,
                    $config,
                );
            }
        } elseif ('' !== $content) {
            $file = $this->storeFetched(
                $this->remoteMediaService->decodeBase64ToTempFile($content, $config['maxBytes']),
                $folder,
                '',
                $item,
                $config,
            );
        } else {
            throw new \RuntimeException('Provide a url or base64 content.');
        }

        $this->remoteMediaService->applyMetadata($file, [
            'title' => trim((string) ($item['title'] ?? '')),
            'alternative' => trim((string) ($item['alternative'] ?? '')),
            'description' => trim((string) ($item['description'] ?? '')),
        ]);

        return $file;
    }

    /**
     * @param array<string, mixed>                                                                                                          $item
     * @param array{defaultFolder: string, maxBytes: int, allowedExtensions: list<string>, allowUrlFetch: bool, hostDenylist: list<string>} $config
     */
    private function storeFetched(FetchedMedia $fetched, Folder $folder, string $url, array $item, array $config): File
    {
        try {
            $extension = $this->remoteMediaService->resolveExtension($fetched->mimeType, $url, (string) ($item['fileName'] ?? ''));
            $this->assertExtensionAllowed($extension, $config['allowedExtensions']);
            $baseName = $this->remoteMediaService->resolveBaseName(
                (string) ($item['fileName'] ?? ''),
                $url,
                (string) ($item['title'] ?? ''),
            );

            return $this->remoteMediaService->storeTempFile($folder, $fetched->tempFilePath, $baseName, $extension);
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
            throw new \RuntimeException(sprintf('File type ".%s" is not allowed. Allowed: %s.', $extension, implode(', ', $allowed)));
        }
    }

    /**
     * @return array{defaultFolder: string, maxBytes: int, allowedExtensions: list<string>, allowUrlFetch: bool, hostDenylist: list<string>}
     */
    private function getMediaConfig(): array
    {
        $maxSizeMb = (int) $this->readConfig('mcpMediaMaxSizeMb', '50');
        $allowed = array_values(array_filter(array_map(
            static fn (string $e): string => strtolower(trim($e)),
            explode(',', $this->readConfig('mcpMediaAllowedExtensions', 'jpg,jpeg,png,gif,webp,avif,mp4,webm,ogg')),
        )));
        $denylist = array_values(array_filter(array_map(
            'trim',
            explode(',', $this->readConfig('mcpMediaHostDenylist', '')),
        )));

        return [
            'defaultFolder' => '' !== ($f = $this->readConfig('mcpMediaDefaultFolder', '1:/user_upload/')) ? $f : '1:/user_upload/',
            'maxBytes' => $maxSizeMb > 0 ? $maxSizeMb * 1024 * 1024 : 0,
            'allowedExtensions' => $allowed,
            'allowUrlFetch' => '0' !== $this->readConfig('mcpMediaAllowUrlFetch', '1'),
            'hostDenylist' => $denylist,
        ];
    }

    private function readConfig(string $key, string $default): string
    {
        try {
            $value = $this->mcpToolContext->extensionConfiguration->get('ai_suite_mcp', $key);
        } catch (\Throwable) {
            return $default;
        }

        if (null === $value || '' === $value || is_array($value)) {
            return $default;
        }

        return (string) $value;
    }
}
