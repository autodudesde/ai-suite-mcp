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
        return 'Copy one or more records (including relations and child records) to a target page. '
            .'For a single copy pass table, uid and targetPid. '
            .'To copy several records in one call, pass a "copies" array — each item is {table, uid, targetPid}.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'copies' => [
                    'type' => 'array',
                    'description' => 'Batch mode: array of copies. Each item: {table, uid, targetPid}. '
                        .'When provided, the top-level table/uid/targetPid are ignored.',
                    'items' => ['type' => 'object'],
                ],
                'table' => ['type' => 'string', 'description' => 'TCA table name (single copy).'],
                'uid' => ['type' => 'integer', 'description' => 'UID of the record to copy (single copy).'],
                'targetPid' => ['type' => 'integer', 'description' => 'Target page UID where the copy will be placed.'],
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $copies = $params['copies'] ?? null;

        if (is_array($copies)) {
            if (empty($copies)) {
                return $this->textError('copies must be a non-empty array.');
            }

            return $this->batchResultBuilder->run($copies, 'copy/copies', function (mixed $copy): array {
                if (!is_array($copy)) {
                    throw new InvalidParameterException('Skipped (not an object).');
                }

                return $this->performCopy($copy);
            });
        }

        return $this->textResult($this->performCopy($params)['message']);
    }

    /**
     * @param array<string, mixed> $copy
     *
     * @return array{message: string, uid: null|int}
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
            throw new \RuntimeException('Copy failed: '.implode(', ', $dh->errorLog));
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

        return ['message' => $text, 'uid' => null !== $newUid ? (int) $newUid : null];
    }
}
