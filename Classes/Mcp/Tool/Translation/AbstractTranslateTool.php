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
use AutoDudes\AiSuiteMcp\Mcp\Service\TranslatedPageSlugService;
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
        protected readonly TranslatedPageSlugService $translatedPageSlugs,
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
            ['text' => 'Translation models'],
        );
    }

    /**
     * @param array<string, mixed> $glossary
     */
    protected function describeSelfTranslation(
        string $targetLanguage,
        string $sourceLanguage,
        array $glossary,
        string $globalInstructions,
    ): string {
        $text = sprintf(
            'Translate the field values below from %s into %s and write them back with `writeRecords`, '
            .'using the record UIDs given here — those records already exist and are empty or still hold the source text. '
            .'Keep every HTML tag, attribute and entity exactly as it is and translate only the text between them. '
            ."Do not translate field names, and do not add or drop fields.\n\n"
            // The shape is spelled out because the UIDs below are the translations themselves: a model
            // that reaches for `translations` here asks for a translation of a translation and, once
            // refused, has been measured filling `fields` with the source text instead.
            ."One entry per record, addressed by its uid, and no `translations` key:\n"
            ."`{\"records\": [{\"table\": \"pages\", \"uid\": 22, \"fields\": {\"title\": \"…\"}}]}`\n\n",
            '' !== $sourceLanguage ? strtoupper($sourceLanguage) : 'the source language',
            strtoupper($targetLanguage),
        );

        if ([] !== $glossary) {
            $text .= "**Glossary — these terms are binding:**\n";
            foreach ($glossary as $key => $entry) {
                $source = \is_array($entry) ? (string) ($entry['source'] ?? $entry['term'] ?? '') : (string) $key;
                $target = \is_array($entry) ? (string) ($entry['target'] ?? $entry['translation'] ?? '') : (string) $entry;
                if ('' !== $source && '' !== $target) {
                    $text .= sprintf("- %s → %s\n", $source, $target);
                }
            }
            $text .= "\n";
        }

        if ('' !== trim($globalInstructions)) {
            $text .= "**Editorial instructions:**\n".trim($globalInstructions)."\n\n";
        }

        return $text."Translating this yourself is the intended path and costs nothing. Only if the request named a translation model, call this tool again with `model` to have the AI Suite Server translate instead.\n\n";
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function describeUntranslated(array $result): string
    {
        $untranslated = $result['untranslated'] ?? [];
        if (!\is_array($untranslated) || [] === $untranslated) {
            return '';
        }

        return sprintf(
            "\n**Warning:** the model returned no translation for %s. These kept their original text — the records exist in the target language but are not translated. Translating again, or with another model, is the fix.\n",
            implode(', ', array_map('strval', $untranslated)),
        );
    }

    /**
     * @param list<int> $uids
     */
    protected function revealTranslations(string $table, array $uids): void
    {
        $hiddenField = $this->tcaCompatibilityService->getDisabledFieldName($table);
        if (null === $hiddenField) {
            return;
        }

        foreach ($uids as $uid) {
            $this->writeViaDataHandler($table, $uid, [$hiddenField => 0]);
        }
    }

    /**
     * @param array<array-key, mixed> $translationResults
     * @param array<array-key, mixed> $sentFields
     *
     * @return array<string, array<array-key, array<array-key, mixed>>>
     */
    protected function restrictToSentFields(array $translationResults, array $sentFields): array
    {
        $restricted = [];
        foreach ($translationResults as $table => $records) {
            if (!\is_array($records) || !\is_array($sentFields[$table] ?? null)) {
                continue;
            }
            foreach ($records as $uid => $fields) {
                $sent = $sentFields[$table][$uid] ?? null;
                if (!\is_array($fields) || !\is_array($sent)) {
                    continue;
                }
                $kept = array_intersect_key($fields, $sent);
                if ([] !== $kept) {
                    $restricted[(string) $table][$uid] = $this->translationService->claimTranslatedFields((string) $table, $kept);
                }
            }
        }

        return $restricted;
    }

    /**
     * @param array<array-key, mixed> $translatedRecords
     */
    protected function refreshTranslatedPageSlugs(array $translatedRecords): void
    {
        $pages = \is_array($translatedRecords['pages'] ?? null) ? $translatedRecords['pages'] : [];
        foreach ($pages as $pageUid => $fields) {
            if (\is_array($fields)) {
                $this->translatedPageSlugs->refreshInheritedSlug((int) $pageUid, $fields);
            }
        }
    }

    protected function translateSingleRecord(
        string $table,
        int $uid,
        string $targetLanguage,
        string $model,
        string $sourceLanguage = '',
        bool $hidden = true,
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

        $pageId = (int) ($record['pid'] ?: 1);

        if ('' !== $model) {
            $this->permissionService->validateModelAccess($model);
        }

        if ('' === $sourceLanguage) {
            $sourceLanguage = $this->resolveLanguageIsoCode('', $pageId);
        }

        $srcLangUid = $this->recordAccess->resolveLanguageUid($sourceLanguage, $pageId);
        $destLangUid = $this->recordAccess->resolveLanguageUid($targetLanguage, $pageId);

        if (0 === $destLangUid) {
            return $this->textError("Language \"{$targetLanguage}\" is not configured for this site.");
        }

        $this->recordAccess->assertLanguageAccess($destLangUid);

        $translatedUid = $this->translationService->findOrCreateLocalization($table, $uid, $destLangUid);

        if (null === $translatedUid) {
            return $this->textError('Could not create or find localization record.');
        }

        $skipped = [];
        $translateFields = $this->collectTranslatableTree($table, $uid, $destLangUid, (int) $translatedUid, $record, $skipped);

        if (!$hidden) {
            $this->revealTranslations($table, [(int) $translatedUid]);
            foreach ($translateFields as $revealTable => $records) {
                $this->revealTranslations((string) $revealTable, array_map('intval', array_keys($records)));
            }
        }

        if (empty($translateFields)) {
            return $this->structuredResult(
                sprintf(
                    "Nothing to translate in %s:%d, it holds no translatable text. Its %s translation `%s:%d` is in place, so the element is rendered in that language.\n",
                    $table,
                    $uid,
                    $targetLanguage,
                    $table,
                    (int) $translatedUid,
                ).$this->describeSkippedRecords($skipped),
                ['translation' => [
                    'mode' => 'none',
                    'table' => $table,
                    'sourceUid' => $uid,
                    'uid' => (int) $translatedUid,
                    'targetLanguage' => $targetLanguage,
                    'skipped' => $skipped,
                ]],
            );
        }

        $recordCount = array_sum(array_map('count', $translateFields));
        $translateFieldsJson = json_encode($translateFields, SendRequestService::JSON_SAFE_FLAGS);

        $site = $this->siteFinder->getSiteByPageId($pageId);
        $rootPageId = $site->getRootPageId();
        $glossarEntries = $this->glossarService->findGlossarEntries((string) $translateFieldsJson, $destLangUid, $srcLangUid);
        $glossary = $this->glossarService->findDeeplGlossary($rootPageId, $srcLangUid, $destLangUid);

        $globalInstructions = $this->globalInstructionService->buildGlobalInstruction($table, 'translation', $pageId);

        if ('' === $model) {
            return $this->structuredResult(
                sprintf("## Translate %s:%d → %s yourself\n\n", $table, $uid, $targetLanguage)
                    .$this->describeSelfTranslation($targetLanguage, $sourceLanguage, $glossarEntries, $globalInstructions)
                    .$this->describeHandedOverRecords($translateFields)
                    .$this->describeSkippedRecords($skipped),
                ['translation' => [
                    'mode' => 'self',
                    'table' => $table,
                    'sourceUid' => $uid,
                    'uid' => (int) $translatedUid,
                    'targetLanguage' => $targetLanguage,
                    'sourceLanguage' => $sourceLanguage,
                    'records' => $translateFields,
                    'skipped' => $skipped,
                ]],
            );
        }

        $uuid = $this->uuidService->generateUuid();

        $result = $this->sendAiRequest('translate', [
            'translate_fields' => $translateFieldsJson,
            'translate_fields_count' => $recordCount,
            'glossary' => json_encode($glossarEntries, SendRequestService::JSON_SAFE_FLAGS),
            'source_lang' => strtoupper($sourceLanguage),
            'target_lang' => strtoupper($targetLanguage),
            'uuid' => $uuid,
            'deepl_glossary_id' => $glossary['glossar_uuid'] ?? '',
            'global_instructions' => $globalInstructions,
        ], ['translate' => $model], strtoupper($targetLanguage));

        $translationResults = $result['translationResults'] ?? [];
        if (\is_string($translationResults)) {
            $translationResults = json_decode($translationResults, true) ?? [];
        }

        if (empty($translationResults)) {
            return $this->textError('No translation results returned by the server.');
        }

        $cleanedResults = $this->restrictToSentFields($translationResults, $translateFields);

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

        $this->refreshTranslatedPageSlugs($cleanedResults);

        $text = $this->appendDataFlowInfo('', $model);
        $text .= sprintf("## Translation complete: %s:%d → %s\n\n", $table, $uid, $targetLanguage);
        $text .= sprintf("**Translation record:** %s:%d\n", $table, (int) $translatedUid);

        foreach ($cleanedResults as $resultTable => $records) {
            foreach ($records as $resultUid => $recFields) {
                $text .= sprintf("### %s:%s\n", $resultTable, $resultUid);
                foreach ($recFields as $field => $value) {
                    if (\is_array($value)) {
                        continue;
                    }
                    $displayValue = strip_tags((string) $value);
                    if (mb_strlen($displayValue) > 120) {
                        $displayValue = mb_substr($displayValue, 0, 120).'...';
                    }
                    $text .= sprintf("- **%s**: %s\n", $field, $displayValue);
                }
                $text .= "\n";
            }
        }

        if ($hidden) {
            $text .= "\n**Note:** Translated records are hidden by default (TYPO3 standard). Use `readPageContent` with `includeHidden: true` to verify.\n";
        }
        $text .= $this->describeUntranslated($result);
        $text .= $this->describeSkippedRecords($skipped);

        $untranslated = \is_array($result['untranslated'] ?? null) ? array_map('strval', $result['untranslated']) : [];

        return $this->appendCreditInfo(
            $this->structuredResult($text, ['translation' => [
                'table' => $table,
                'sourceUid' => $uid,
                'uid' => (int) $translatedUid,
                'targetLanguage' => $targetLanguage,
                'model' => $model,
                'records' => $recordCount,
                'untranslated' => array_values($untranslated),
                'skipped' => $skipped,
            ]]),
            $result,
        );
    }

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
        return $this->translationService->collectRecordTreeTranslatableFields(
            $table,
            $uid,
            $targetLanguageUid,
            $this->userContext->getServerRequest(),
            $skipped,
        );
    }

    /**
     * @param array<string, mixed> $translateFields
     */
    protected function describeHandedOverRecords(array $translateFields): string
    {
        $text = "**Write the translated values to these records, using exactly these field names:**\n";
        foreach ($translateFields as $table => $records) {
            if (!\is_array($records)) {
                continue;
            }
            foreach ($records as $uid => $fields) {
                $names = \is_array($fields) ? array_keys($fields) : [];
                $text .= [] === $names
                    ? sprintf("- `%s:%s`\n", $table, $uid)
                    : sprintf("- `%s:%s` — %s\n", $table, $uid, implode(', ', array_map('strval', $names)));
            }
        }

        return $text."\nThe field values themselves are in this answer's structured content, under `translation.records`.\n";
    }

    /**
     * @param array<string, string> $skipped
     */
    protected function describeSkippedRecords(array $skipped): string
    {
        if ([] === $skipped) {
            return '';
        }

        $text = "\n**Skipped, not part of this translation:**\n";
        foreach ($skipped as $record => $reason) {
            $text .= sprintf("- `%s`: %s\n", $record, $reason);
        }

        return $text;
    }
}
