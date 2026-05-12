<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Create a translation shell for a record using TYPO3's built-in localization.
 * No AI, no credits — the record is created with empty/copied fields ready to be translated.
 */
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
        return 'Create a translation of a record using TYPO3 built-in localization — no credits. '
            .'Mode "localize" creates an empty linked shell; "copyToLanguage" creates an independent full copy. '
            .'Returns the UID of the new translation record. Use that UID with writeRecords to fill in the translated content.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'TCA table name (e.g. pages, tt_content)'],
                'uid' => ['type' => 'integer', 'description' => 'UID of the default-language record to translate'],
                'targetLanguage' => ['type' => 'string', 'description' => 'ISO target language code (de, en, fr, es, ...)'],
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

        $this->validateTableWriteAccess($table);

        if (!$this->tcaCompatibilityService->isLanguageAware($table)) {
            return new CallToolResult(
                [new TextContent(sprintf('Table "%s" does not support translations.', $table))],
                isError: true,
            );
        }

        $record = $this->assertRecordReadAccess($table, $uid);

        $pageId = 'pages' === $table ? $uid : (int) ($record['pid'] ?? 0);
        $targetLanguageUid = $this->resolveLanguageUid($targetLanguage, $pageId);

        if (0 === $targetLanguageUid) {
            return new CallToolResult(
                [new TextContent(sprintf('Language "%s" not found or is the default language.', $targetLanguage))],
                isError: true,
            );
        }

        $this->assertLanguageAccess($targetLanguageUid);

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([], [$table => [$uid => [$mode => $targetLanguageUid]]]);
        $dh->process_cmdmap();

        if ([] !== $dh->errorLog) {
            throw new \RuntimeException('Localization failed: '.implode(', ', $dh->errorLog));
        }

        $newUid = $dh->copyMappingArray[$table][$uid] ?? null;
        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $recordLabel = $record[$labelField] ?? $uid;

        $text = sprintf(
            '%s "%s" (UID: %d) translated to %s via %s.',
            $this->getTableLabel($table),
            $recordLabel,
            $uid,
            $targetLanguage,
            $mode,
        );

        if (null !== $newUid) {
            $text .= sprintf("\n\nTranslation created: UID %d (table: %s, language: %s)", $newUid, $table, $targetLanguage);
            $text .= sprintf("\nUse writeRecords with uid: %d to edit the translation.", $newUid);
            $text .= sprintf("\n\n**Note:** The new record is hidden by default (TYPO3 standard). Use `getPageContent` with `includeHidden: true` to see it.");
        }

        return new CallToolResult([new TextContent($text)]);
    }
}
