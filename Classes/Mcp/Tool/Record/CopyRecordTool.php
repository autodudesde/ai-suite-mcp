<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Copy a record (with relations) to a target page via DataHandler.
 * No AI, no credits.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class CopyRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function getName(): string
    {
        return 'copyRecord';
    }

    public function getDescription(): string
    {
        return 'Copy a record (including relations and child records) to a target page.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'TCA table name'],
                'uid' => ['type' => 'integer', 'description' => 'UID of the record to copy'],
                'targetPid' => ['type' => 'integer', 'description' => 'Target page UID where the copy will be placed'],
            ],
            'required' => ['table', 'uid', 'targetPid'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $uid = (int) $params['uid'];
        $targetPid = (int) $params['targetPid'];

        $this->validateTableWriteAccess($table);

        $record = $this->assertRecordReadAccess($table, $uid);
        $this->assertRecordCreateAccess($table, $targetPid);

        $targetPage = BackendUtility::getRecordWSOL('pages', $targetPid);
        if (null === $targetPage) {
            return new CallToolResult([new TextContent(sprintf('Target page %d not found.', $targetPid))], isError: true);
        }

        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $recordLabel = $record[$labelField] ?? $uid;

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([], [$table => [$uid => ['copy' => $targetPid]]]);
        $dh->process_cmdmap();

        if ([] !== $dh->errorLog) {
            throw new \RuntimeException('Copy failed: '.implode(', ', $dh->errorLog));
        }

        $newUid = $dh->copyMappingArray[$table][$uid] ?? null;

        $text = sprintf(
            '%s "%s" (UID: %d) copied to page %d.',
            $this->getTableLabel($table),
            $recordLabel,
            $uid,
            $targetPid,
        );

        if (null !== $newUid) {
            $text .= sprintf(' New record UID: %d.', $newUid);
        }

        return new CallToolResult([new TextContent($text)]);
    }
}
