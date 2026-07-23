<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class LocalizeRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function getName(): string
    {
        return 'localizeRecord';
    }

    public function getDescription(): string
    {
        // "no credits" is the disambiguator against translateRecord, which does the same thing with
        // an external AI model and bills for it. The verb alone cannot carry that.
        return 'Create a translation of a record with the built-in TYPO3 localization (writes, no credits). '
            .'Mode "localize" creates an empty linked shell; "copyToLanguage" creates an independent full copy. '
            .'Returns the UID of the new translation record, which is empty until content is written into it.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'TCA table name (e.g. pages, tt_content)'],
                'uid' => ['type' => 'integer', 'description' => 'UID of the default-language record to translate'],
                'targetLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO target language code (de, en, fr, es, ...)',
                ]),
                'mode' => [
                    'type' => 'string',
                    'enum' => ['localize', 'copyToLanguage'],
                    'default' => 'localize',
                    'description' => '"localize" = connected translation (linked to parent), "copyToLanguage" = independent copy. Default: localize.',
                ],
            ],
            'required' => ['table', 'uid', 'targetLanguage'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $uid = (int) $params['uid'];
        $targetLanguage = (string) $params['targetLanguage'];
        $mode = (string) ($params['mode'] ?? 'localize');

        $this->recordAccess->validateTableWriteAccess($table);

        if (!$this->tcaCompatibilityService->isLanguageAware($table)) {
            return new CallToolResult(
                [new TextContent(sprintf('Table "%s" does not support translations.', $table))],
                isError: true,
            );
        }

        $record = $this->recordAccess->assertRecordReadAccess($table, $uid);

        $pageId = 'pages' === $table ? $uid : (int) ($record['pid'] ?? 0);
        $targetLanguageUid = $this->recordAccess->resolveLanguageUid($targetLanguage, $pageId);

        if (0 === $targetLanguageUid) {
            return new CallToolResult(
                [new TextContent(sprintf('Language "%s" not found or is the default language.', $targetLanguage))],
                isError: true,
            );
        }

        $this->recordAccess->assertLanguageAccess($targetLanguageUid);

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([], [$table => [$uid => [$mode => $targetLanguageUid]]]);
        $dh->process_cmdmap();

        if ([] !== $dh->errorLog) {
            throw $this->dataHandlerError->toException('localization', $table, $uid, $dh->errorLog);
        }

        $newUid = $dh->copyMappingArray[$table][$uid] ?? null;
        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $recordLabel = $record[$labelField] ?? $uid;

        $text = sprintf(
            '%s "%s" (UID: %d) translated to %s via %s.',
            $this->tcaLabel->getTableLabel($table),
            $recordLabel,
            $uid,
            $targetLanguage,
            $mode,
        );

        if (null !== $newUid) {
            $text .= sprintf("\n\nTranslation created: UID %d (table: %s, language: %s)", $newUid, $table, $targetLanguage);
            $text .= sprintf("\nUse writeRecords with uid: %d to edit the translation.", $newUid);
            $text .= sprintf("\n\n**Note:** The new record is hidden by default (TYPO3 standard). Use `readPageContent` with `includeHidden: true` to see it.");
        }

        return $this->textResult($text);
    }
}
