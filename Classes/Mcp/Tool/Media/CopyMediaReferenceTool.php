<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Media;

use AutoDudes\AiSuiteMcp\Domain\Repository\SysFileReferenceRepository;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordWriteService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\Record\AbstractDataTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class CopyMediaReferenceTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly RecordWriteService $recordWrite,
        private readonly BatchResultBuilderService $batchResultBuilder,
        private readonly SysFileReferenceRepository $fileReferenceRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'copyMediaReference';
    }

    public function getDescription(): string
    {
        return 'Copy the file reference(s) from a source record field to a target record field (writes), pointing at '
            .'the same underlying file (sys_file). Reuses an image/asset on another element without re-uploading. '
            .'Requires write access to the target.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sourceTable' => ['type' => 'string', 'description' => 'Table of the record that currently holds the reference.'],
                'sourceUid' => ['type' => 'integer', 'description' => 'UID of the source record.'],
                'sourceField' => ['type' => 'string', 'description' => 'Field on the source record holding the file reference(s).'],
                'targetTable' => ['type' => 'string', 'description' => 'Table of the record to copy the reference onto.'],
                'targetUid' => ['type' => 'integer', 'description' => 'UID of the target record.'],
                'targetField' => ['type' => 'string', 'description' => 'Field on the target record to attach the reference to.'],
            ],
            'required' => ['sourceTable', 'sourceUid', 'sourceField', 'targetTable', 'targetUid', 'targetField'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $sourceTable = (string) $params['sourceTable'];
        $sourceUid = (int) $params['sourceUid'];
        $sourceField = (string) $params['sourceField'];
        $targetTable = (string) $params['targetTable'];
        $targetUid = (int) $params['targetUid'];
        $targetField = (string) $params['targetField'];

        $this->recordAccess->assertRecordReadAccess($sourceTable, $sourceUid);
        $this->recordAccess->validateTableWriteAccess($targetTable);
        $targetRecord = $this->recordAccess->assertRecordEditAccess($targetTable, $targetUid);
        $this->recordAccess->filterAccessibleFields($targetTable, [$targetField => '']);

        $references = $this->fileReferenceRepository->findReferences($sourceTable, $sourceUid, $sourceField);
        if ([] === $references) {
            return $this->textError(
                sprintf('No file reference found on %s:%d field "%s".', $sourceTable, $sourceUid, $sourceField),
                McpErrorType::NotFound,
            );
        }

        $targetPid = 'pages' === $targetTable ? $targetUid : (int) ($targetRecord['pid'] ?? 0);

        return $this->batchResultBuilder->run(
            $references,
            'reference(s)',
            function (mixed $reference) use ($targetTable, $targetUid, $targetField, $targetPid): array {
                /** @var array{uid: int, uid_local: int, pid: int} $reference */
                $result = $this->recordWrite->create('sys_file_reference', $targetPid, [
                    'uid_local' => $reference['uid_local'],
                    'uid_foreign' => $targetUid,
                    'tablenames' => $targetTable,
                    'fieldname' => $targetField,
                ]);

                return [
                    'message' => sprintf('file %d attached to %s:%d.%s (reference UID: %d)', $reference['uid_local'], $targetTable, $targetUid, $targetField, $result->uid),
                    'uid' => $result->uid,
                ];
            },
        );
    }
}
