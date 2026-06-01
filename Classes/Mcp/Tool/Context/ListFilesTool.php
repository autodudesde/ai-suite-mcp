<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuiteMcp\Mcp\Service\FilePreviewService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\Content;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ListFilesTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly FilePreviewService $filePreviewService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'listFiles';
    }

    public function getDescription(): string
    {
        return 'List files in a FAL storage folder. Filter by type and missing metadata. '
            .'Supports thumbnails (base64) for visual preview when needed. '
            .'Returns only folders/files within your file mounts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'folderIdentifier' => [
                    'type' => 'string',
                    'description' => 'FAL folder identifier, e.g. "1:/user_upload/"',
                    'default' => '1:/user_upload/',
                ],
                'fileType' => [
                    'type' => 'string',
                    'enum' => ['all', 'image', 'document', 'video', 'audio'],
                    'default' => 'all',
                    'description' => 'Filter by file type. Default: all.',
                ],
                'onlyMissingMetadata' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Only files with empty alt text, title or description',
                ],
                'includeThumbnails' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include 50x50px base64 thumbnails for image files. Increases response size.',
                ],
                'recursive' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include files from subfolders. Default: false.',
                ],
                'limit' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 200, 'description' => 'Max files to return. Default: 10.'],
                'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Skip first N files for pagination. Default: 0.'],
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $folderInput = (string) ($params['folderIdentifier'] ?? '1:/user_upload/');
        $fileType = $params['fileType'] ?? 'all';
        $onlyMissing = (bool) ($params['onlyMissingMetadata'] ?? false);
        $includeThumbnails = (bool) ($params['includeThumbnails'] ?? false);
        $recursive = (bool) ($params['recursive'] ?? false);
        $limit = (int) ($params['limit'] ?? 10);
        $offset = (int) ($params['offset'] ?? 0);

        $combinedIdentifier = $folderInput;
        if (!preg_match('/^\d+:/', $combinedIdentifier)) {
            $combinedIdentifier = '1:'.$combinedIdentifier;
        }
        [$storageUid, $folderPath] = explode(':', $combinedIdentifier, 2);
        $storageUid = (int) $storageUid;
        $folderPath = rtrim($folderPath, '/').'/';
        $combinedIdentifier = $storageUid.':'.$folderPath;

        try {
            $folder = $this->recordAccess->assertFolderReadAccess($combinedIdentifier);
            $storage = $folder->getStorage();
        } catch (\Throwable $e) {
            $this->logger->error('ListFiles: folder not accessible, aborting listing', [
                'storageUid' => $storageUid,
                'folderPath' => $folderPath,
                'error' => $e->getMessage(),
            ]);

            return new CallToolResult(
                [new TextContent(sprintf('Folder not accessible: %s (Storage %d). Error: %s', $folderPath, $storageUid, $e->getMessage()))],
                isError: true,
            );
        }

        $fileObjects = $recursive
            ? $this->getFilesRecursive($storage, $folder, 0, 10)
            : $storage->getFilesInFolder($folder);

        $filtered = [];
        foreach ($fileObjects as $fileObject) {
            if (!$fileObject instanceof File) {
                continue;
            }

            if ('all' !== $fileType) {
                $mimePrefix = match ($fileType) {
                    'image' => 'image/',
                    'video' => 'video/',
                    'audio' => 'audio/',
                    'document' => 'application/',
                    default => '',
                };
                if ('' !== $mimePrefix && !str_starts_with($fileObject->getMimeType(), $mimePrefix)) {
                    continue;
                }
            }

            if ($onlyMissing) {
                $meta = $fileObject->getMetaData()->get();
                $hasAlt = '' !== trim((string) ($meta['alternative'] ?? ''));
                $hasTitle = '' !== trim((string) ($meta['title'] ?? ''));
                $hasDesc = '' !== trim((string) ($meta['description'] ?? ''));
                if ($hasAlt && $hasTitle && $hasDesc) {
                    continue;
                }
            }

            $filtered[] = $fileObject;
        }

        $total = count($filtered);
        $paged = array_slice($filtered, $offset, $limit);

        $files = [];
        foreach ($paged as $file) {
            $meta = $file->getMetaData()->get();
            $files[] = [
                'uid' => $file->getUid(),
                'name' => $file->getName(),
                'extension' => $file->getExtension(),
                'mimeType' => $file->getMimeType(),
                'size' => $file->getSize(),
                'identifier' => $file->getIdentifier(),
                'publicUrl' => $this->filePreviewService->getAbsolutePublicUrl($file),
                'hasAltText' => '' !== trim((string) ($meta['alternative'] ?? '')),
                'hasTitle' => '' !== trim((string) ($meta['title'] ?? '')),
                'hasDescription' => '' !== trim((string) ($meta['description'] ?? '')),
            ];
        }

        /** @var list<Content> $content */
        $content = [new TextContent((string) json_encode([
            'folder' => $storageUid.':'.$folderPath,
            'files' => $files,
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'hasMore' => ($offset + $limit) < $total],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))];

        if ($includeThumbnails) {
            foreach ($paged as $file) {
                $thumbnail = $this->filePreviewService->generate($file, 50, 50);
                if (null !== $thumbnail) {
                    $content[] = $thumbnail;
                }
            }
        }

        return new CallToolResult($content);
    }

    /**
     * @return list<File>
     */
    private function getFilesRecursive(ResourceStorage $storage, Folder $folder, int $depth, int $maxDepth): array
    {
        $files = array_values($storage->getFilesInFolder($folder));

        if ($depth >= $maxDepth) {
            return $files;
        }

        foreach ($storage->getFoldersInFolder($folder) as $subfolder) {
            $files = array_merge($files, $this->getFilesRecursive($storage, $subfolder, $depth + 1, $maxDepth));
        }

        return $files;
    }
}
