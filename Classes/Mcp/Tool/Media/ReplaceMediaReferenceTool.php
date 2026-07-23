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
class ReplaceMediaReferenceTool extends AbstractDataTool
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
        return 'replaceMediaReference';
    }

    public function getDescription(): string
    {
        return 'Replace the file behind an existing file reference on a record field with a different file (writes), '
            .'keeping the reference in place. Swaps an image without recreating the reference. '
            .'Requires write access to the record.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'Table of the record holding the reference.'],
                'uid' => ['type' => 'integer', 'description' => 'UID of the record.'],
                'field' => ['type' => 'string', 'description' => 'Field holding the file reference(s).'],
                'fileUid' => ['type' => 'integer', 'description' => 'UID of the new sys_file to point the reference(s) at.'],
            ],
            'required' => ['table', 'uid', 'field', 'fileUid'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $uid = (int) $params['uid'];
        $field = (string) $params['field'];
        $fileUid = (int) $params['fileUid'];

        $this->recordAccess->validateTableWriteAccess($table);
        $this->recordAccess->assertRecordEditAccess($table, $uid);
        $this->recordAccess->filterAccessibleFields($table, [$field => '']);
        $this->recordAccess->assertFileReadAccess($fileUid);

        $references = $this->fileReferenceRepository->findReferences($table, $uid, $field);
        if ([] === $references) {
            return $this->textError(
                sprintf('No file reference found on %s:%d field "%s".', $table, $uid, $field),
                McpErrorType::NotFound,
            );
        }

        return $this->batchResultBuilder->run(
            $references,
            'reference(s)',
            function (mixed $reference) use ($fileUid): array {
                $this->recordWrite->update('sys_file_reference', $reference['uid'], ['uid_local' => $fileUid]);

                return [
                    'message' => sprintf('reference %d now points at file %d (was %d)', $reference['uid'], $fileUid, $reference['uid_local']),
                    'uid' => $reference['uid'],
                ];
            },
        );
    }
}
