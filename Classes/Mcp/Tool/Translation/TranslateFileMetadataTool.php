<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Translation;

use AutoDudes\AiSuite\Domain\Repository\SysFileMetadataRepository;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\GlossarService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\TranslationService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TranslatedSlugService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class TranslateFileMetadataTool extends AbstractTranslateTool
{
    protected ?string $requiredScope = 'mcp:translate';

    public function __construct(
        ToolContext $mcpToolContext,
        LibraryService $libraryService,
        UuidService $uuidService,
        TranslationService $translationService,
        GlossarService $glossarService,
        GlobalInstructionService $globalInstructionService,
        TranslatedSlugService $translatedSlugs,
        private readonly SysFileMetadataRepository $sysFileMetadataRepository,
    ) {
        parent::__construct($mcpToolContext, $libraryService, $uuidService, $translationService, $glossarService, $globalInstructionService, $translatedSlugs);
    }

    public function getName(): string
    {
        return 'translateFileMetadata';
    }

    public function getDescription(): string
    {
        return 'Translate a file\'s metadata — alt text, title, description — into a target language, using DeepL and the glossary of the site named by pageId '
            .DescriptionSnippets::COSTS_CREDITS.'. '
            .'Writes the translation itself; localizeRecord only creates an empty translation shell.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileUid' => ['type' => 'integer', 'description' => 'sys_file UID'],
                'targetLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO target language',
                ]),
                'model' => ['type' => 'string', 'description' => 'Translation model identifier. Omit to get a list of available models first.'],
                'sourceLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO source language. Default: site default language.',
                ]),
                'pageId' => ['type' => 'integer', 'description' => 'Optional. UID of any page of the site whose glossary applies; files belong to no site, so without it no glossary is used.'],
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
        $glossaryPageId = max(0, (int) ($params['pageId'] ?? 0));
        if ($glossaryPageId > 0) {
            $this->recordAccess->assertPagePerm($glossaryPageId, Permission::PAGE_SHOW);
        }

        try {
            $this->recordAccess->assertFileReadAccess($fileUid);
        } catch (\RuntimeException $e) {
            $this->logger->warning('TranslateFileMetadata: file not found', [
                'fileUid' => $fileUid,
                'reason' => $e->getMessage(),
            ]);

            return $this->textError(sprintf('File UID %d not found.', $fileUid));
        }

        $metadataUids = $this->sysFileMetadataRepository->findDefaultLanguageMetadataUidsByFileUids([$fileUid]);
        $metadataUid = $metadataUids[$fileUid] ?? null;

        if (null === $metadataUid) {
            return $this->textError(sprintf('No metadata record found for file UID %d.', $fileUid));
        }

        return $this->translateSingleRecord(
            'sys_file_metadata',
            (int) $metadataUid,
            (string) $params['targetLanguage'],
            $model,
            (string) ($params['sourceLanguage'] ?? ''),
            glossaryPageId: $glossaryPageId,
        );
    }

    // Deliberately not the tree collector: file metadata has no inline children to translate.
    /**
     * @param array<string, mixed>  $record
     * @param array<string, string> $skipped
     *
     * @param-out array<string, string> $skipped
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function collectTranslatableTree(string $table, int $uid, int $targetLanguageUid, int $translatedUid, array $record, array &$skipped): array
    {
        $fields = [];
        foreach (['title', 'alternative', 'description'] as $field) {
            $value = $record[$field] ?? '';
            if ('' !== $value) {
                $fields[$field] = $value;
            }
        }

        return [] === $fields ? [] : [$table => [$translatedUid => $fields]];
    }
}
