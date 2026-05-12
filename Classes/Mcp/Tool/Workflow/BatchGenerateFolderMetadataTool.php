<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuiteMcp\Mcp\ToolDescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Batch-generate file metadata for all files in one or more FAL folders.
 * Resolves folder paths to file UIDs, then delegates to the same logic as BatchGenerateFileMetadataTool.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class BatchGenerateFolderMetadataTool extends BatchGenerateFileMetadataTool
{
    public function getName(): string
    {
        return 'batchGenerateFolderMetadata';
    }

    public function getDescription(): string
    {
        return 'Generate file metadata (alt text, title, description) for all files in one or more FAL folders using an external AI model — costs credits per file. '
            .'For specific files by UID, use batchGenerateFileMetadata instead. '
            .ToolDescriptionSnippets::BATCH_ASYNC_FLOW;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'folderIdentifiers' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Array of FAL folder paths (e.g. ["1:/user_upload/", "1:/images/"]). Processes all files in these folders.',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => ['alternative', 'title'],
                    'description' => 'Metadata fields to generate: alternative (alt text), title, description. Default: alternative, title.',
                ],
                'model' => ['type' => 'string', 'description' => 'AI model identifier (e.g. Vision). Omit to list available models.'],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
            ],
            'required' => ['folderIdentifiers'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $folderIdentifiers = $params['folderIdentifiers'] ?? [];

        if (empty($folderIdentifiers)) {
            return new CallToolResult([new TextContent('folderIdentifiers must be a non-empty array.')], isError: true);
        }

        // Resolve all folders to file UIDs
        $fileUids = [];
        foreach ($folderIdentifiers as $folder) {
            try {
                $fileUids = array_merge($fileUids, $this->resolveFileUidsFromFolder((string) $folder));
            } catch (\Throwable $e) {
                $this->logger->error('BatchGenerateFolderMetadata: folder not accessible, aborting batch', [
                    'folder' => $folder,
                    'error' => $e->getMessage(),
                ]);

                return new CallToolResult(
                    [new TextContent(sprintf('Folder not accessible: %s. Error: %s', $folder, $e->getMessage()))],
                    isError: true,
                );
            }
        }

        if (empty($fileUids)) {
            return new CallToolResult([new TextContent('No files found in the specified folders.')], isError: true);
        }

        // Delegate to parent with resolved UIDs
        $params['fileUids'] = array_unique($fileUids);
        unset($params['folderIdentifiers']);

        return parent::doExecute($params);
    }
}
