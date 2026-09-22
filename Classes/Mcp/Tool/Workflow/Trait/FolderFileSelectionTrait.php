<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow\Trait;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

trait FolderFileSelectionTrait
{
    /**
     * @return array{type: string, items: array<string, string>, description: string}
     */
    protected static function folderIdentifiersSchemaProperty(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'FAL folder paths whose files to process, e.g. ["/user_upload/presse/"] — the storage prefix is optional and defaults to 1, so a plain path from the editor works as it stands. Alternative to fileUids; give one of the two.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|CallToolResult
     */
    protected function withFilesFromFolders(array $params): array|CallToolResult
    {
        $folderIdentifiers = $params['folderIdentifiers'] ?? [];
        unset($params['folderIdentifiers']);

        if (!\is_array($folderIdentifiers) || [] === $folderIdentifiers) {
            return $params;
        }

        $fileUids = [];
        foreach ($folderIdentifiers as $folder) {
            try {
                $fileUids = array_merge($fileUids, $this->resolveFileUidsFromFolder((string) $folder));
            } catch (\Throwable $e) {
                $this->logger->error('Batch metadata: folder not accessible, aborting batch', [
                    'folder' => $folder,
                    'error' => $e->getMessage(),
                ]);

                return new CallToolResult(
                    [new TextContent(sprintf('Folder not accessible: %s. Error: %s', $folder, $e->getMessage()))],
                    isError: true,
                );
            }
        }

        if ([] === $fileUids) {
            return $this->textError('No files found in the specified folders.');
        }

        $params['fileUids'] = array_values(array_unique(array_merge(
            array_map('intval', \is_array($params['fileUids'] ?? null) ? $params['fileUids'] : []),
            $fileUids,
        )));

        return $params;
    }
}
