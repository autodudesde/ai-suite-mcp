<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Translation;

use AutoDudes\AiSuite\Domain\Repository\SysFileMetadataRepository;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\GlossarService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\TranslationService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use AutoDudes\AiSuiteMcp\Mcp\ToolDescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Translate file metadata (alt text, title, description) to a target language.
 * Resolves fileUid → sys_file_metadata UID, then delegates to AbstractTranslateTool::translateSingleRecord().
 * Use batchTranslateFileMetadata for translating metadata of multiple files at once.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class TranslateFileMetadataTool extends AbstractTranslateTool
{
    protected ?string $requiredScope = 'mcp:translate';

    public function __construct(
        McpToolContext $mcpToolContext,
        LibraryService $libraryService,
        UuidService $uuidService,
        TranslationService $translationService,
        GlossarService $glossarService,
        GlobalInstructionService $globalInstructionService,
        private readonly SysFileMetadataRepository $sysFileMetadataRepository,
    ) {
        parent::__construct($mcpToolContext, $libraryService, $uuidService, $translationService, $glossarService, $globalInstructionService);
    }

    public function getName(): string
    {
        return 'translateFileMetadata';
    }

    public function getDescription(): string
    {
        return 'Translate file metadata (alt text, title, description) to a target language. Two approaches: '
            .ToolDescriptionSnippets::APPROACH_A
            .'(B) Use localizeRecord to create a translation shell → translate manually '.ToolDescriptionSnippets::APPROACH_B_PERSIST.' '
            .ToolDescriptionSnippets::APPROACH_A_PREVIEW_AND_PERSIST;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileUid' => ['type' => 'integer', 'description' => 'sys_file UID'],
                'targetLanguage' => ['type' => 'string', 'description' => 'ISO target language'],
                'model' => ['type' => 'string', 'description' => 'Translation model identifier. Omit to get a list of available models first.'],
                'sourceLanguage' => ['type' => 'string', 'description' => 'ISO source language. Default: site default language.'],
            ],
            'required' => ['fileUid', 'targetLanguage'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $model = (string) ($params['model'] ?? '');

        if ('' === $model) {
            return $this->listTranslationModels();
        }

        $fileUid = (int) $params['fileUid'];

        // Validate file existence + filemount permission
        try {
            $this->assertFileReadAccess($fileUid);
        } catch (\RuntimeException $e) {
            $this->logger->warning('TranslateFileMetadata: file not found', [
                'fileUid' => $fileUid,
                'reason' => $e->getMessage(),
            ]);

            return new CallToolResult([new TextContent(sprintf('File UID %d not found.', $fileUid))], isError: true);
        }

        // Resolve fileUid → sys_file_metadata UID (default language)
        $metadataUids = $this->sysFileMetadataRepository->findDefaultLanguageMetadataUidsByFileUids([$fileUid]);
        $metadataUid = $metadataUids[$fileUid] ?? null;

        if (null === $metadataUid) {
            return new CallToolResult([new TextContent(sprintf('No metadata record found for file UID %d.', $fileUid))], isError: true);
        }

        return $this->translateSingleRecord(
            'sys_file_metadata',
            (int) $metadataUid,
            (string) $params['targetLanguage'],
            $model,
            (string) ($params['sourceLanguage'] ?? ''),
        );
    }

    /**
     * Collect translatable fields directly from the record.
     *
     * sys_file_metadata has pid=0, which breaks FormDataCompiler (Clipboard requires BE module context).
     * Instead, read the translatable field values directly — same approach as BatchTranslateFileMetadataTool.
     *
     * @param array<string, mixed> $record
     */
    protected function collectTranslatableFields(string $table, int $uid, array $record): array
    {
        $fields = [];
        foreach (['title', 'alternative', 'description'] as $field) {
            $value = $record[$field] ?? '';
            if ('' !== $value) {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }
}
