<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Translation;

use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class TranslatePageTool extends AbstractTranslateTool implements SelfTranslatingToolInterface
{
    protected ?string $requiredScope = 'mcp:translate';

    public function getName(): string
    {
        return 'translatePage';
    }

    public function getDescription(): string
    {
        return 'Translate a whole page — metadata, content elements and their inline children — using the site glossary. '
            .'Without a model you translate the handed-back fields yourself, for free; with one the server translates '
            .DescriptionSnippets::COSTS_CREDITS.'. Creates the translation records either way.';
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
                'model' => ['type' => 'string', 'description' => 'Optional. Omit to translate the fields yourself — the tool then prepares the translation records and hands you their fields, glossary and editorial instructions, and nothing is sent to the AI Suite Server. Name a model to have the server translate instead, which costs credits.'],
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
                'hidden' => ['type' => 'boolean', 'default' => true, 'description' => 'Keep the translation hidden, as TYPO3 creates it (default). Pass false to make it visible right away.'],
            ],
            'required' => ['pageId', 'targetLanguage'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $targetLanguage = (string) $params['targetLanguage'];
        $translationScope = (string) ($params['translationScope'] ?? 'all');
        $model = (string) ($params['model'] ?? '');
        $hidden = (bool) ($params['hidden'] ?? true);

        $page = $this->validatePageForAi($pageId, Permission::CONTENT_EDIT);
        if ($page instanceof CallToolResult) {
            return $page;
        }

        if ('' !== $model) {
            $this->permissionService->validateModelAccess($model);
        }

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

        $request = $this->userContext->getServerRequest();

        $skipped = [];

        try {
            $translateFields = $this->translationService->collectTranslatableFieldsWithMapping(
                $pageId,
                $srcLangUid,
                $destLangUid,
                $translationScope,
                $request,
                $skipped,
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

        if (!$hidden) {
            foreach ($translateFields as $table => $records) {
                $this->revealTranslations((string) $table, array_map('intval', array_keys($records)));
            }
        }
        $translateFieldsJson = json_encode($translateFields, SendRequestService::JSON_SAFE_FLAGS);

        $site = $this->siteFinder->getSiteByPageId($pageId);
        $rootPageId = $site->getRootPageId();
        $glossarEntries = $this->glossarService->findGlossarEntries((string) $translateFieldsJson, $destLangUid, $srcLangUid);
        $glossary = $this->glossarService->findDeeplGlossary($rootPageId, $srcLangUid, $destLangUid);

        $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('pages', 'translation', $pageId);

        if ('' === $model) {
            $pageTranslationUid = 'content' !== $translationScope
                ? $this->translationService->findOrCreateLocalization('pages', $pageId, $destLangUid)
                : null;

            return $this->structuredResult(
                sprintf("## Translate page %d → %s yourself\n\n", $pageId, $targetLanguage)
                    .sprintf("**Scope:** %s | **Elements:** %d\n\n", $translationScope, $elementsCount)
                    .(null !== $pageTranslationUid ? sprintf("**Page translation:** `pages:%d`\n\n", $pageTranslationUid) : '')
                    .$this->describeSelfTranslation($targetLanguage, $sourceLanguage, $glossarEntries, $globalInstructions)
                    .$this->describeHandedOverRecords($translateFields)
                    .$this->describeSkippedRecords($skipped),
                ['translation' => [
                    'mode' => 'self',
                    'pageId' => $pageId,
                    'pageTranslationUid' => $pageTranslationUid,
                    'targetLanguage' => $targetLanguage,
                    'sourceLanguage' => $sourceLanguage,
                    'scope' => $translationScope,
                    'elements' => $elementsCount,
                    'records' => $translateFields,
                    'skipped' => $skipped,
                ]],
            );
        }

        $uuid = $this->uuidService->generateUuid();

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
        $text .= sprintf("## Translation complete: Page %d → %s\n\n", $pageId, $targetLanguage);
        $text .= sprintf("**Scope:** %s | **Elements:** %d\n\n", $translationScope, $elementsCount);

        foreach ($cleanedResults as $table => $records) {
            foreach ($records as $uid => $fields) {
                $text .= sprintf("### %s:%s\n", $table, $uid);
                foreach ($fields as $field => $value) {
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
            $text .= "**Note:** Translated records are hidden by default (TYPO3 standard). Use `readPageContent` with `includeHidden: true` to verify.\n";
        }
        $text .= $this->describeUntranslated($result);
        $text .= $this->describeSkippedRecords($skipped);

        $untranslated = \is_array($result['untranslated'] ?? null) ? array_map('strval', $result['untranslated']) : [];

        return $this->appendCreditInfo(
            $this->structuredResult($text, ['translation' => [
                'pageId' => $pageId,
                'targetLanguage' => $targetLanguage,
                'scope' => $translationScope,
                'elements' => $elementsCount,
                'model' => $model,
                'untranslated' => array_values($untranslated),
                'skipped' => $skipped,
            ]]),
            $result,
        );
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
