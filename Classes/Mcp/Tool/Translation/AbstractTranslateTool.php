<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Translation;

use AutoDudes\AiSuite\Enumeration\CreditCostEnumeration;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\GlossarService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuite\Service\TranslationService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared base for single-record translation tools.
 *
 * Provides listTranslationModels() and translateSingleRecord() so that
 * TranslateRecordTool and TranslateFileMetadataTool share the same logic.
 */
abstract class AbstractTranslateTool extends AbstractAiTool
{
    public function __construct(
        McpToolContext $mcpToolContext,
        protected readonly LibraryService $libraryService,
        protected readonly UuidService $uuidService,
        protected readonly TranslationService $translationService,
        protected readonly GlossarService $glossarService,
        protected readonly GlobalInstructionService $globalInstructionService,
    ) {
        parent::__construct($mcpToolContext);
    }

    protected function listTranslationModels(): CallToolResult
    {
        return $this->listAvailableModels(
            $this->libraryService,
            GenerationLibraryEnumeration::TRANSLATE,
            'translate',
            ['text'],
            CreditCostEnumeration::TRANSLATION,
            ['text' => 'Translation models'],
        );
    }

    /**
     * Translate a single record: localize → collect fields → AI request → apply via DataHandler.
     *
     * Reuses TranslationService::fetchTranslationFields() and the same server payload format
     * as TranslationHook, so it works for any TCA table with language support.
     */
    protected function translateSingleRecord(
        string $table,
        int $uid,
        string $targetLanguage,
        string $model,
        string $sourceLanguage = '',
    ): CallToolResult {
        // Permission + WSOL: assertRecordEditAccess returns the workspace-overlaid row and throws
        // InsufficientPermissionException if the user cannot edit it. Missing record → \RuntimeException → 404.
        try {
            $record = $this->assertRecordEditAccess($table, $uid);
        } catch (\RuntimeException $e) {
            $this->logger->warning('TranslateSingleRecord: record not found', [
                'table' => $table,
                'uid' => $uid,
                'reason' => $e->getMessage(),
            ]);

            return new CallToolResult([new TextContent(sprintf('%s:%d not found.', $table, $uid))], isError: true);
        }

        // Records with pid=0 (e.g. sys_file_metadata) have no page context —
        // use pageId=1 as fallback for language resolution (same convention as BatchTranslateFileMetadataTool).
        $pageId = (int) ($record['pid'] ?: 1);

        $this->permissionService->validateModelAccess($model);

        if ('' === $sourceLanguage) {
            $sourceLanguage = $this->resolveLanguageIsoCode('', $pageId);
        }

        $srcLangUid = $this->resolveLanguageUid($sourceLanguage, $pageId);
        $destLangUid = $this->resolveLanguageUid($targetLanguage, $pageId);

        if (0 === $destLangUid) {
            return new CallToolResult([new TextContent("Language \"{$targetLanguage}\" is not configured for this site.")], isError: true);
        }

        $this->assertLanguageAccess($destLangUid);

        // Step 1: Ensure localization record exists (find existing or create via DataHandler)
        $translatedUid = $this->translationService->findOrCreateLocalization($table, $uid, $destLangUid);

        if (null === $translatedUid) {
            return new CallToolResult([new TextContent('Could not create or find localization record.')], isError: true);
        }

        // Step 2: Collect translatable fields (overridable for tables like sys_file_metadata)
        $fields = $this->collectTranslatableFields($table, $uid, $record);

        if (empty($fields)) {
            return new CallToolResult([new TextContent('No translatable fields found in this record.')], isError: true);
        }

        $translateFields = [$table => [(int) $translatedUid => $fields]];
        $translateFieldsJson = json_encode($translateFields, SendRequestService::JSON_SAFE_FLAGS);

        // Step 3: Load glossary
        $site = $this->siteFinder->getSiteByPageId($pageId);
        $rootPageId = $site->getRootPageId();
        $glossarEntries = $this->glossarService->findGlossarEntries((string) $translateFieldsJson, $destLangUid, $srcLangUid);
        $glossary = $this->glossarService->findDeeplGlossary($rootPageId, $srcLangUid, $destLangUid);

        $globalInstructions = $this->globalInstructionService->buildGlobalInstruction($table, 'translation', $pageId);
        $uuid = $this->uuidService->generateUuid();

        // Step 4: Send in same format as TranslationHook
        $result = $this->sendAiRequest('translate', [
            'translate_fields' => $translateFieldsJson,
            'translate_fields_count' => 1,
            'glossary' => json_encode($glossarEntries, SendRequestService::JSON_SAFE_FLAGS),
            'source_lang' => strtoupper($sourceLanguage),
            'target_lang' => strtoupper($targetLanguage),
            'uuid' => $uuid,
            'deepl_glossary_id' => $glossary['glossar_uuid'] ?? '',
            'global_instructions' => $globalInstructions,
        ], ['translate' => $model], strtoupper($targetLanguage));

        // Step 5: Apply translation results
        $translationResults = $result['translationResults'] ?? [];
        if (\is_string($translationResults)) {
            $translationResults = json_decode($translationResults, true) ?? [];
        }

        if (empty($translationResults)) {
            return new CallToolResult([new TextContent('No translation results returned by the server.')], isError: true);
        }

        $cleanedResults = [];
        foreach ($translationResults as $tbl => $records) {
            if (!\is_array($records)) {
                continue;
            }
            foreach ($records as $recUid => $recFields) {
                if (\is_array($recFields)) {
                    $cleanedResults[$tbl][$recUid] = $recFields;
                }
            }
        }

        if (empty($cleanedResults)) {
            return new CallToolResult([new TextContent('Translation results could not be processed (invalid format).')], isError: true);
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

        // Build response
        $text = $this->appendDataFlowInfo('', $model);
        $text .= sprintf("## Translation complete: %s:%d → %s\n\n", $table, $uid, $targetLanguage);

        foreach ($translationResults as $tbl => $records) {
            if (!\is_array($records)) {
                continue;
            }
            foreach ($records as $recUid => $recFields) {
                if (!\is_array($recFields)) {
                    continue;
                }
                foreach ($recFields as $field => $value) {
                    $displayValue = strip_tags((string) $value);
                    if (mb_strlen($displayValue) > 120) {
                        $displayValue = mb_substr($displayValue, 0, 120).'...';
                    }
                    $text .= sprintf("- **%s**: %s\n", $field, $displayValue);
                }
            }
        }

        $text .= "\n**Note:** Translated records are hidden by default (TYPO3 standard). Use `getPageContent` with `includeHidden: true` to verify.\n";

        return $this->appendCreditInfo(new CallToolResult([new TextContent($text)]), $result);
    }

    /**
     * Collect translatable fields for a record.
     *
     * Default implementation uses TranslationService::fetchTranslationFields() (FormDataCompiler-based).
     * Override for tables where FormDataCompiler doesn't work (e.g. sys_file_metadata with pid=0).
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed> field name => field value
     */
    protected function collectTranslatableFields(string $table, int $uid, array $record): array
    {
        $request = $this->userContext->getServerRequest();
        $fields = $this->translationService->fetchTranslationFields($request, [], $uid, $table);

        return array_filter($fields, static function ($field) {
            return !\is_array($field) || isset($field['data']);
        });
    }
}
