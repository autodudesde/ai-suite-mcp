<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Translation;

use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\GlossarService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuite\Service\TranslationService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class TranslatePageTool extends AbstractAiTool
{
    protected ?string $requiredScope = 'mcp:translate';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly TranslationService $translationService,
        private readonly GlossarService $glossarService,
        private readonly GlobalInstructionService $globalInstructionService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'translatePage';
    }

    public function getDescription(): string
    {
        return 'Translate a whole page — metadata and every content element — into a target language, using DeepL and the site glossary '
            .DescriptionSnippets::COSTS_CREDITS.'. '
            .'Writes the translation itself; localizeRecord only creates empty translation shells.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'Page UID to translate'],
                'targetLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO target language code.',
                ]),
                'model' => ['type' => 'string', 'description' => 'Translation model identifier. Omit to get a list of available models first.'],
                'sourceLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO source language. Default: site default language.',
                ]),
                'translationScope' => [
                    'type' => 'string',
                    'enum' => ['all', 'metadata', 'content'],
                    'default' => 'all',
                    'description' => 'What to translate: "all" (metadata + content), "metadata" (SEO fields only), "content" (content elements only).',
                ],
            ],
            'required' => ['pageId', 'targetLanguage'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $targetLanguage = (string) $params['targetLanguage'];
        $model = (string) ($params['model'] ?? '');
        $translationScope = (string) ($params['translationScope'] ?? 'all');

        if ('' === $model) {
            return $this->listAvailableModels(
                $this->libraryService,
                GenerationLibraryEnumeration::TRANSLATE,
                'translate',
                ['text'],
                ['text' => 'Translation models'],
            );
        }

        $page = $this->validatePageForAi($pageId, Permission::CONTENT_EDIT);
        if ($page instanceof CallToolResult) {
            return $page;
        }

        $this->permissionService->validateModelAccess($model);

        $sourceLanguage = (string) ($params['sourceLanguage'] ?? '');
        if ('' === $sourceLanguage) {
            $sourceLanguage = $this->resolveLanguageIsoCode('', $pageId);
        }

        $srcLangUid = $this->recordAccess->resolveLanguageUid($sourceLanguage, $pageId);
        $destLangUid = $this->recordAccess->resolveLanguageUid($targetLanguage, $pageId);

        if (0 === $destLangUid) {
            return $this->textError("Language \"{$targetLanguage}\" is not configured for this site.");
        }

        $this->recordAccess->assertLanguageAccess($destLangUid);

        // Collect translatable fields with correct translation UID mapping
        $request = $this->userContext->getServerRequest();

        try {
            $translateFields = $this->translationService->collectTranslatableFieldsWithMapping(
                $pageId,
                $srcLangUid,
                $destLangUid,
                $translationScope,
                $request,
            );
        } catch (\RuntimeException $e) {
            $this->logger->error('TranslatePage: collecting translatable fields failed, aborting translation', [
                'pageId' => $pageId,
                'srcLangUid' => $srcLangUid,
                'destLangUid' => $destLangUid,
                'reason' => $e->getMessage(),
            ]);

            return $this->textError($e->getMessage());
        }

        $elementsCount = $this->countElements($translateFields);
        $translateFieldsJson = json_encode($translateFields, SendRequestService::JSON_SAFE_FLAGS);

        // Load glossary
        $site = $this->siteFinder->getSiteByPageId($pageId);
        $rootPageId = $site->getRootPageId();
        $glossarEntries = $this->glossarService->findGlossarEntries((string) $translateFieldsJson, $destLangUid, $srcLangUid);
        $glossary = $this->glossarService->findDeeplGlossary($rootPageId, $srcLangUid, $destLangUid);

        $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('pages', 'translation', $pageId);
        $uuid = $this->uuidService->generateUuid();

        // Send request in the same format as TranslationHook
        $result = $this->sendAiRequest('translate', [
            'translate_fields' => $translateFieldsJson,
            'translate_fields_count' => $elementsCount,
            'glossary' => json_encode($glossarEntries, SendRequestService::JSON_SAFE_FLAGS),
            'source_lang' => strtoupper($sourceLanguage),
            'target_lang' => strtoupper($targetLanguage),
            'uuid' => $uuid,
            'deepl_glossary_id' => $glossary['glossar_uuid'] ?? '',
            'whole_page_mode' => true,
            'scope' => 'page',
            'global_instructions' => $globalInstructions,
        ], ['translate' => $model], strtoupper($targetLanguage));

        // Apply translation results via DataHandler
        $translationResults = $result['translationResults'] ?? [];
        if (\is_string($translationResults)) {
            $translationResults = json_decode($translationResults, true) ?? [];
        }

        if (empty($translationResults)) {
            return $this->textError('No translation results returned by the server.');
        }

        $cleanedResults = [];
        foreach ($translationResults as $table => $records) {
            if (!\is_array($records)) {
                continue;
            }
            foreach ($records as $uid => $fields) {
                if (\is_array($fields)) {
                    $cleanedResults[$table][$uid] = $fields;
                }
            }
        }

        if (empty($cleanedResults)) {
            return $this->textError('Translation results could not be processed (invalid format).');
        }

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start($cleanedResults, []);
        $dh->process_datamap();

        if ([] !== $dh->errorLog) {
            return new CallToolResult(
                [new TextContent('Translation saved with errors: '.implode(', ', $dh->errorLog))],
                isError: true,
            );
        }

        $text = $this->appendDataFlowInfo('', $model);
        $text .= sprintf("## Translation complete: Page %d → %s\n\n", $pageId, $targetLanguage);
        $text .= sprintf("**Scope:** %s | **Elements:** %d\n\n", $translationScope, $elementsCount);

        foreach ($cleanedResults as $table => $records) {
            foreach ($records as $uid => $fields) {
                $text .= sprintf("### %s:%s\n", $table, $uid);
                foreach ($fields as $field => $value) {
                    $displayValue = strip_tags((string) $value);
                    if (mb_strlen($displayValue) > 120) {
                        $displayValue = mb_substr($displayValue, 0, 120).'...';
                    }
                    $text .= sprintf("- **%s**: %s\n", $field, $displayValue);
                }
                $text .= "\n";
            }
        }

        $text .= "**Note:** Translated records are hidden by default (TYPO3 standard). Use `readPageContent` with `includeHidden: true` to verify.\n";

        return $this->appendCreditInfo($this->textResult($text), $result);
    }

    /**
     * @param array<string, mixed> $translateFields
     */
    private function countElements(array $translateFields): int
    {
        $count = 0;
        foreach ($translateFields as $records) {
            $count += \count($records);
        }

        return $count;
    }
}
