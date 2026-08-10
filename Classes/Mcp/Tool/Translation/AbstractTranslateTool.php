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
            ."Do not translate field names, and do not add or drop fields.\n\n",
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

        return $text.sprintf(
            "To have the AI Suite Server translate instead — which costs credits — call this tool again with `model`. Available: %s.\n\n",
            implode(', ', $this->permittedTranslationModels()),
        );
    }

    /**
     * @return list<string>
     */
    protected function permittedTranslationModels(): array
    {
        try {
            $answer = $this->sendRequestService->sendLibrariesRequest(
                GenerationLibraryEnumeration::TRANSLATE,
                'translate',
                ['text'],
            );
            if ('Error' === $answer->getType()) {
                return ['none reachable'];
            }
            $libraries = $answer->getResponseData()['textGenerationLibraries'] ?? [];
            $permitted = $this->libraryService->prepareLibraries(\is_array($libraries) ? $libraries : []);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not list translation models for the self-translation hint', [
                'reason' => $e->getMessage(),
            ]);

            return ['none reachable'];
        }

        $identifiers = array_values(array_map(
            static fn (array $library): string => (string) $library['model_identifier'],
            $permitted,
        ));

        return [] === $identifiers ? ['none permitted for this user'] : $identifiers;
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

        $fields = $this->collectTranslatableFields($table, $uid, $record);

        if (empty($fields)) {
            return $this->textError('No translatable fields found in this record.');
        }

        $translateFields = [$table => [(int) $translatedUid => $fields]];
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
                    .sprintf("**Write the translated values to `%s:%d`.**\n", $table, (int) $translatedUid),
                ['translation' => [
                    'mode' => 'self',
                    'table' => $table,
                    'sourceUid' => $uid,
                    'uid' => (int) $translatedUid,
                    'targetLanguage' => $targetLanguage,
                    'sourceLanguage' => $sourceLanguage,
                    'fields' => $fields,
                ]],
            );
        }

        $uuid = $this->uuidService->generateUuid();

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
        $text .= sprintf("**Translation record:** %s:%d\n", $table, (int) $translatedUid);

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

        $text .= "\n**Note:** Translated records are hidden by default (TYPO3 standard). Use `readPageContent` with `includeHidden: true` to verify.\n";
        $text .= $this->describeUntranslated($result);

        $untranslated = \is_array($result['untranslated'] ?? null) ? array_map('strval', $result['untranslated']) : [];

        return $this->appendCreditInfo(
            $this->structuredResult($text, ['translation' => [
                'table' => $table,
                'sourceUid' => $uid,
                'uid' => (int) $translatedUid,
                'targetLanguage' => $targetLanguage,
                'model' => $model,
                'untranslated' => array_values($untranslated),
            ]]),
            $result,
        );
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
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
