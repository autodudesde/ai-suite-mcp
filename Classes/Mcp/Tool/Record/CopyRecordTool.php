<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class CopyRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BatchResultBuilderService $batchResultBuilder,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'copyRecords';
    }

    public function getDescription(): string
    {
        return 'Duplicate one or more records onto a target page, including their relations and child records (writes). '
            .'The original stays where it is; moveRecords relocates instead of duplicating.';
    }

    public function getSchema(): array
    {
        // Array-only. The former dual mode (a `copies` array *or* top-level table/uid/targetPid,
        // with zero required properties) cannot be expressed in JSON Schema, so the model had to
        // guess a mode from prose. One shape, one required property.
        return [
            'type' => 'object',
            'properties' => [
                'copies' => [
                    'type' => 'array',
                    'description' => 'The records to copy. Each: {table, uid, targetPid}, all required. Pass one entry even for a single copy.',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['copies'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $copies = $params['copies'] ?? null;

        if (!is_array($copies) || empty($copies)) {
            return $this->textError('copies must be a non-empty array of {table, uid, targetPid}.');
        }

        return $this->batchResultBuilder->run($copies, 'copy/copies', function (mixed $copy): array {
            if (!is_array($copy)) {
                throw new InvalidParameterException('Skipped (not an object).');
            }

            return $this->performCopy($copy);
        });
    }

    /**
     * @param array<string, mixed> $copy
     *
     * @return array{message: string, uid: null|int, table: string, action: string}
     */
    private function performCopy(array $copy): array
    {
        $table = (string) ($copy['table'] ?? '');
        $uid = (int) ($copy['uid'] ?? 0);
        $targetPid = isset($copy['targetPid']) ? (int) $copy['targetPid'] : null;

        if ('' === $table || $uid <= 0 || null === $targetPid) {
            throw new \RuntimeException('Provide table, uid and targetPid.');
        }

        $this->recordAccess->validateTableWriteAccess($table);

        $record = $this->recordAccess->assertRecordReadAccess($table, $uid);
        $this->recordAccess->assertRecordCreateAccess($table, $targetPid);

        $targetPage = BackendUtility::getRecordWSOL('pages', $targetPid);
        if (null === $targetPage) {
            throw new \RuntimeException(sprintf('Target page %d not found.', $targetPid));
        }

        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $recordLabel = $record[$labelField] ?? $uid;

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([], [$table => [$uid => ['copy' => $targetPid]]]);
        $dh->process_cmdmap();

        if ([] !== $dh->errorLog) {
            throw $this->dataHandlerError->toException('copy', $table, $uid, $dh->errorLog);
        }

        $newUid = $dh->copyMappingArray[$table][$uid] ?? null;

        $text = sprintf(
            '%s "%s" (UID: %d) copied to page %d.',
            $this->tcaLabel->getTableLabel($table),
            $recordLabel,
            $uid,
            $targetPid,
        );

        if (null !== $newUid) {
            $text .= sprintf(' New record UID: %d.', $newUid);
        }

        return [
            'message' => $text,
            'uid' => null !== $newUid ? (int) $newUid : null,
            'table' => $table,
            'action' => 'create',
        ];
    }
}
