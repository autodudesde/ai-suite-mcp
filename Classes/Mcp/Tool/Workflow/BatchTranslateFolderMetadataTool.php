<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class BatchTranslateFolderMetadataTool extends BatchTranslateFileMetadataTool
{
    public function getName(): string
    {
        return 'batchTranslateFolderMetadata';
    }

    public function getDescription(): string
    {
        return 'Translate file metadata (alt text, title, description) for every file in one or more FAL folders with an external AI model (costs credits). '
            .'For specific files by UID, use batchTranslateFileMetadata instead. '
            .DescriptionSnippets::BATCH_ASYNC;
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
                    'description' => 'Metadata fields to translate: alternative (alt text), title, description. Default: all three.',
                ],
                'model' => ['type' => 'string', 'description' => 'Translation model identifier (e.g. DeepL). Omit to list available models.'],
            ],
            'required' => ['folderIdentifiers', 'targetLanguage'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $folderIdentifiers = $params['folderIdentifiers'] ?? [];

        if (empty($folderIdentifiers)) {
            return $this->textError('folderIdentifiers must be a non-empty array.');
        }

        $fileUids = [];
        foreach ($folderIdentifiers as $folder) {
            try {
                $fileUids = array_merge($fileUids, $this->resolveFileUidsFromFolder((string) $folder));
            } catch (\Throwable $e) {
                $this->logger->error('BatchTranslateFolderMetadata: folder not accessible, aborting batch', [
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
            return $this->textError('No files found in the specified folders.');
        }

        $params['fileUids'] = array_unique($fileUids);
        unset($params['folderIdentifiers']);

        return parent::doExecute($params);
    }
}
