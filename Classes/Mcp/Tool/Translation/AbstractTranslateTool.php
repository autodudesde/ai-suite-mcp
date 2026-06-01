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
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractTranslateTool extends AbstractAiTool
{
    public function __construct(
        ToolContext $mcpToolContext,
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

    protected function translateSingleRecord(
        string $table,
        int $uid,
        string $targetLanguage,
        string $model,
        string $sourceLanguage = '',
    ): CallToolResult {
        try {
            $record = $this->recordAccess->assertRecordEditAccess($table, $uid);
        } catch (\RuntimeException $e) {
            $this->logger->warning('TranslateSingleRecord: record not found', [
                'table' => $table,
                'uid' => $uid,
                'reason' => $e->getMessage(),
            ]);

            return $this->textError(sprintf('%s:%d not found.', $table, $uid));
        }

        // Records with pid=0 (e.g. sys_file_metadata) have no page context
        // use pageId=1 as fallback for language resolution
        $pageId = (int) ($record['pid'] ?: 1);

        $this->permissionService->validateModelAccess($model);

        if ('' === $sourceLanguage) {
            $sourceLanguage = $this->resolveLanguageIsoCode('', $pageId);
        }

        $srcLangUid = $this->recordAccess->resolveLanguageUid($sourceLanguage, $pageId);
        $destLangUid = $this->recordAccess->resolveLanguageUid($targetLanguage, $pageId);

        if (0 === $destLangUid) {
            return $this->textError("Language \"{$targetLanguage}\" is not configured for this site.");
        }

        $this->recordAccess->assertLanguageAccess($destLangUid);

        // Ensure localization record exists (find existing or create via DataHandler)
        $translatedUid = $this->translationService->findOrCreateLocalization($table, $uid, $destLangUid);

        if (null === $translatedUid) {
            return $this->textError('Could not create or find localization record.');
        }

        // Collect translatable fields (overridable for tables like sys_file_metadata)
        $fields = $this->collectTranslatableFields($table, $uid, $record);

        if (empty($fields)) {
            return $this->textError('No translatable fields found in this record.');
        }

        $translateFields = [$table => [(int) $translatedUid => $fields]];
        $translateFieldsJson = json_encode($translateFields, SendRequestService::JSON_SAFE_FLAGS);

        // Load glossary
        $site = $this->siteFinder->getSiteByPageId($pageId);
        $rootPageId = $site->getRootPageId();
        $glossarEntries = $this->glossarService->findGlossarEntries((string) $translateFieldsJson, $destLangUid, $srcLangUid);
        $glossary = $this->glossarService->findDeeplGlossary($rootPageId, $srcLangUid, $destLangUid);

        $globalInstructions = $this->globalInstructionService->buildGlobalInstruction($table, 'translation', $pageId);
        $uuid = $this->uuidService->generateUuid();

        // Send in same format as TranslationHook
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

        //  Apply translation results
        $translationResults = $result['translationResults'] ?? [];
        if (\is_string($translationResults)) {
            $translationResults = json_decode($translationResults, true) ?? [];
        }

        if (empty($translationResults)) {
            return $this->textError('No translation results returned by the server.');
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

        return $this->appendCreditInfo($this->textResult($text), $result);
    }

    /**
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
