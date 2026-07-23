<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordWriteService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class BulkReplaceTextTool extends AbstractSafeEditTool
{
    private const MAX_CHILDREN = 500;

    public function __construct(
        ToolContext $mcpToolContext,
        RecordWriteService $recordWrite,
        private readonly BatchResultBuilderService $batchResultBuilder,
        private readonly RecordRepository $recordRepository,
    ) {
        parent::__construct($mcpToolContext, $recordWrite);
    }

    public function getName(): string
    {
        return 'bulkReplaceText';
    }

    public function getDescription(): string
    {
        return 'Replace a literal text fragment in one field across every child record of a parent (writes) — '
            .'e.g. remove a dash from every card in a card group. Children where the search text is absent are '
            .'reported as skipped; the rest still succeed.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'parentUid' => ['type' => 'integer', 'description' => 'UID of the parent record whose children are edited.'],
                'childTable' => ['type' => 'string', 'description' => 'TCA table of the child records (e.g. tx_bootstrappackage_card_group_item).'],
                'relationField' => ['type' => 'string', 'description' => 'Field on the child table that stores the parent UID (e.g. tx_container_parent, or the IRRE foreign_field). Children are the rows of childTable whose relationField equals parentUid.'],
                'field' => ['type' => 'string', 'description' => 'Field on each child to edit (must be writable).'],
                'search' => ['type' => 'string', 'description' => 'Literal text to find (not a regular expression).'],
                'replace' => ['type' => 'string', 'description' => 'Replacement text.'],
                'all' => ['type' => 'boolean', 'default' => false, 'description' => 'Replace every occurrence per child. Default false = require a single unique match per child.'],
            ],
            'required' => ['parentUid', 'childTable', 'relationField', 'field', 'search', 'replace'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $parentUid = (int) $params['parentUid'];
        $childTable = (string) $params['childTable'];
        $relationField = (string) $params['relationField'];
        $field = (string) $params['field'];
        $search = (string) $params['search'];
        $replace = (string) $params['replace'];
        $all = (bool) ($params['all'] ?? false);

        $this->recordAccess->validateTableWriteAccess($childTable);
        $this->recordAccess->filterAccessibleFields($childTable, [$field => '', $relationField => '']);
        $this->assertFieldWritable($childTable, $field);

        $childUids = $this->recordRepository->findUidsByCriteria(
            $childTable,
            null,
            [$relationField => $parentUid],
            null,
            null,
            'uid',
            self::MAX_CHILDREN,
            0,
        );

        if ([] === $childUids) {
            return $this->textError(
                sprintf('No %s records found with %s = %d.', $childTable, $relationField, $parentUid),
                McpErrorType::NotFound,
            );
        }

        return $this->batchResultBuilder->run(
            $childUids,
            'child record(s)',
            function (mixed $uid) use ($childTable, $field, $search, $replace, $all): array {
                $childUid = (int) $uid;
                $record = $this->recordAccess->assertRecordEditAccess($childTable, $childUid);
                $current = array_key_exists($field, $record) ? (string) $record[$field] : '';

                $applied = $this->applyReplacement($current, $search, $replace, $all);
                $this->recordWrite->update($childTable, $childUid, [$field => $applied['result']]);

                return [
                    'message' => sprintf('%s:%d — %d replacement(s) in `%s`', $childTable, $childUid, $applied['count'], $field),
                    'uid' => $childUid,
                ];
            },
        );
    }
}
