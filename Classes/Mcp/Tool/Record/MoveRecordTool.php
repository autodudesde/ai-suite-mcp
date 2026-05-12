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
 * Move a record to a different page or position via DataHandler.
 * No AI, no credits.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class MoveRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function getName(): string
    {
        return 'moveRecord';
    }

    public function getDescription(): string
    {
        return 'Move a record to a different page or position. '
            .'targetPid sets the destination page. Optionally add afterUid to place after a specific record instead of at the top.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'TCA table name'],
                'uid' => ['type' => 'integer', 'description' => 'UID of the record to move'],
                'targetPid' => ['type' => 'integer', 'description' => 'Target page UID (record placed at top of page).'],
                'afterUid' => ['type' => 'integer', 'description' => 'Place after this record UID. If provided, overrides targetPid positioning.'],
            ],
            'required' => ['table', 'uid', 'targetPid'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $uid = (int) $params['uid'];
        $targetPid = $params['targetPid'] ?? null;
        $afterUid = $params['afterUid'] ?? null;

        if (null === $targetPid && null === $afterUid) {
            return new CallToolResult(
                [new TextContent('Provide targetPid or afterUid.')],
                isError: true,
            );
        }

        $this->validateTableWriteAccess($table);

        $record = $this->assertRecordEditAccess($table, $uid);

        // Resolve the effective destination pid for the create-permission check:
        // afterUid → use the pid of the after-record; targetPid → use directly.
        if (null !== $afterUid) {
            $afterRecord = BackendUtility::getRecordWSOL($table, (int) $afterUid);
            if (null === $afterRecord) {
                return new CallToolResult([new TextContent(sprintf('Reference record %s:%d not found.', $table, $afterUid))], isError: true);
            }
            $resolvedTargetPid = (int) ($afterRecord['pid'] ?? 0);
        } else {
            $resolvedTargetPid = (int) $targetPid;
        }
        $this->assertRecordCreateAccess($table, $resolvedTargetPid);

        // DataHandler move convention: positive = page UID, negative = "after record UID"
        $destination = null !== $afterUid ? -1 * abs((int) $afterUid) : (int) $targetPid;

        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $recordLabel = $record[$labelField] ?? $uid;

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([], [$table => [$uid => ['move' => $destination]]]);
        $dh->process_cmdmap();

        if ([] !== $dh->errorLog) {
            throw new \RuntimeException('Move failed: '.implode(', ', $dh->errorLog));
        }

        $text = sprintf(
            '%s "%s" (UID: %d) moved',
            $this->getTableLabel($table),
            $recordLabel,
            $uid,
        );

        if (null !== $afterUid) {
            $text .= sprintf(' after record %d.', (int) $afterUid);
        } else {
            $text .= sprintf(' to page %d.', (int) $targetPid);
        }

        return new CallToolResult([new TextContent($text)]);
    }
}
