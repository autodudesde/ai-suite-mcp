<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\SkippedItemException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordWriteService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class BulkReplaceTextTool extends AbstractSafeEditTool
{
    private const MAX_PAGES = 50;

    // The cost is one DataHandler update per record, so the run is budgeted by records, not pages.
    private const MAX_RECORDS = 1000;
    private const MAX_RECORDS_DRY_RUN = 5000;
    private const MAX_REPLACEMENTS = 200;

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
        return 'Replace literal text in one field across many records at once (writes) — every child of a parent '
            .'record, or every record on one or more pages, which is how a wording change is rolled out over a '
            .'section. Records without the search text are skipped, not failed.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'childTable' => ['type' => 'string', 'description' => 'TCA table of the records to edit (e.g. tt_content or tx_bootstrappackage_card_group_item).'],
                'field' => ['type' => 'string', 'description' => 'Field on each record to edit (must be writable).'],
                'parentUid' => ['type' => 'integer', 'description' => 'Parent-relation mode: UID of the parent whose children are edited. Needs relationField. Excludes pageIds.'],
                'relationField' => ['type' => 'string', 'description' => 'Parent-relation mode: field on childTable holding the parent UID (e.g. tx_container_parent, or the IRRE foreign_field).'],
                'pageIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Page mode: edit every childTable record on these pages (max '.self::MAX_PAGES.' pages, '.self::MAX_RECORDS.' records per run). readPageTree collects the UIDs of a section. Excludes parentUid.',
                ],
                'search' => ['type' => 'string', 'description' => 'Literal text to find (not a regular expression). Use `replacements` for more than one rule.'],
                'replace' => ['type' => 'string', 'description' => 'Replacement text for `search`.'],
                'replacements' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'Several rules in one pass, applied in order to the running value: [{search, replace, all?}]. Max '.self::MAX_REPLACEMENTS.'. Use instead of search/replace.',
                ],
                'all' => ['type' => 'boolean', 'default' => false, 'description' => 'Replace every occurrence per record. Otherwise a single unique match per record is required.'],
                'normalizeWhitespace' => ['type' => 'boolean', 'default' => true, 'description' => 'Ignore line-ending and spacing differences when locating the match. The replacement is spliced into the original, so text outside the match keeps its exact bytes.'],
                'dryRun' => ['type' => 'boolean', 'default' => false, 'description' => 'Report what would change without writing. Surveys up to '.self::MAX_RECORDS_DRY_RUN.' records, so the blast radius can be measured before splitting the write into runs.'],
            ],
            'required' => ['childTable', 'field'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $childTable = (string) $params['childTable'];
        $field = (string) $params['field'];
        $all = (bool) ($params['all'] ?? false);
        $this->dryRun = (bool) ($params['dryRun'] ?? false);
        $this->normalizeWhitespace = (bool) ($params['normalizeWhitespace'] ?? true);

        $replacements = $this->resolveReplacements($params, $all);

        $this->recordAccess->validateTableWriteAccess($childTable);
        $this->recordAccess->filterAccessibleFields($childTable, [$field => '']);
        $this->assertFieldWritable($childTable, $field);

        $uids = $this->collectUids($params, $childTable);
        if ([] === $uids) {
            return $this->textError($this->nothingFoundMessage($params, $childTable), McpErrorType::NotFound);
        }

        $result = $this->batchResultBuilder->run(
            $uids,
            'record(s)',
            function (mixed $uid) use ($childTable, $field, $replacements): array {
                return $this->applyToRecord($childTable, (int) $uid, $field, $replacements);
            },
        );

        if (!$this->dryRun) {
            return $result;
        }

        return $this->prefixDryRun($result);
    }

    /**
     * @param list<array{search: string, replace: string, all: bool}> $replacements
     *
     * @return array{message: string, uid: int, table: string, action: string}
     */
    private function applyToRecord(string $childTable, int $childUid, string $field, array $replacements): array
    {
        $record = $this->recordAccess->assertRecordEditAccess($childTable, $childUid);
        $value = array_key_exists($field, $record) ? (string) $record[$field] : '';

        $total = 0;
        foreach ($replacements as $index => $replacement) {
            try {
                $applied = $this->applyReplacement(
                    $value,
                    $replacement['search'],
                    $replacement['replace'],
                    $replacement['all'],
                    $this->normalizeWhitespace,
                );
            } catch (InvalidParameterException $e) {
                // A rule that matches nothing in this record is not an error here, only across all rules.
                if (McpErrorType::NotFound === $e->getErrorType()) {
                    continue;
                }

                throw (new InvalidParameterException(sprintf('Replacement #%d: %s', $index + 1, $e->getMessage())))
                    ->withErrorType($e->getErrorType())
                ;
            }

            $value = $applied['result'];
            $total += $applied['count'];
        }

        if (0 === $total) {
            throw new SkippedItemException(sprintf('%s:%d — search text not present, left unchanged', $childTable, $childUid));
        }

        $this->persist($childTable, $childUid, [$field => $value]);

        return [
            'message' => sprintf(
                '%s:%d — %d replacement(s) in `%s`%s',
                $childTable,
                $childUid,
                $total,
                $field,
                $this->dryRun ? ' (not written)' : '',
            ),
            'uid' => $childUid,
            'table' => $childTable,
            'action' => 'update',
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array{search: string, replace: string, all: bool}>
     */
    private function resolveReplacements(array $params, bool $all): array
    {
        $raw = $params['replacements'] ?? null;

        if (!is_array($raw) || [] === $raw) {
            if (!isset($params['search'], $params['replace'])) {
                throw new InvalidParameterException('Provide search and replace, or a replacements array.');
            }

            return [['search' => (string) $params['search'], 'replace' => (string) $params['replace'], 'all' => $all]];
        }

        if (count($raw) > self::MAX_REPLACEMENTS) {
            throw new InvalidParameterException(sprintf('At most %d replacements per call.', self::MAX_REPLACEMENTS));
        }

        $resolved = [];
        foreach (array_values($raw) as $index => $entry) {
            if (!is_array($entry) || !isset($entry['search'], $entry['replace'])) {
                throw new InvalidParameterException(sprintf('Replacement #%d needs a search and a replace.', $index + 1));
            }
            if ('' === (string) $entry['search']) {
                throw new InvalidParameterException(sprintf('Replacement #%d has an empty search.', $index + 1));
            }

            $resolved[] = [
                'search' => (string) $entry['search'],
                'replace' => (string) $entry['replace'],
                'all' => (bool) ($entry['all'] ?? $all),
            ];
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<int>
     */
    private function collectUids(array $params, string $childTable): array
    {
        $parentUid = isset($params['parentUid']) ? (int) $params['parentUid'] : null;
        $explicitPageIds = is_array($params['pageIds'] ?? null) && [] !== $params['pageIds'];

        if ($explicitPageIds && null !== $parentUid) {
            throw new InvalidParameterException('Give either pageIds (page mode) or parentUid (parent-relation mode), not both.');
        }

        $pageIds = $this->resolvePageIds($params);
        if (null !== $pageIds) {
            return $this->collectByPages($pageIds, $childTable);
        }

        if (null === $parentUid) {
            throw new InvalidParameterException('Provide parentUid + relationField, or pageIds.');
        }

        $relationField = (string) ($params['relationField'] ?? '');
        if ('' === $relationField) {
            throw new InvalidParameterException('parentUid needs relationField (the field on childTable holding the parent UID).');
        }
        $this->recordAccess->filterAccessibleFields($childTable, [$relationField => '']);

        $this->assertWithinBudget(
            $this->recordRepository->countByCriteria($childTable, [$relationField => $parentUid]),
            sprintf('%s records with %s = %d', $childTable, $relationField, $parentUid),
        );

        return $this->recordRepository->findUidsByCriteria(
            $childTable,
            null,
            [$relationField => $parentUid],
            null,
            null,
            'uid',
            $this->recordBudget(),
            0,
        );
    }

    private function recordBudget(): int
    {
        return $this->dryRun ? self::MAX_RECORDS_DRY_RUN : self::MAX_RECORDS;
    }

    private function assertWithinBudget(int $found, string $subject): void
    {
        if ($found <= $this->recordBudget()) {
            return;
        }

        throw new InvalidParameterException(sprintf(
            'This run would touch %d %s, more than the %d allowed per call%s. Split it into smaller runs — a partly applied rollout that timed out mid-way is worse than a refused call.',
            $found,
            $subject,
            $this->recordBudget(),
            $this->dryRun ? ' in a dry run' : ' (a dry run may survey up to '.self::MAX_RECORDS_DRY_RUN.')',
        ));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return null|list<int>
     */
    private function resolvePageIds(array $params): ?array
    {
        $pageIds = $params['pageIds'] ?? null;

        // "relationField: pid" reads as page mode, but pid is not a TCA column and would be rejected.
        if (null === $pageIds && 'pid' === (string) ($params['relationField'] ?? '') && isset($params['parentUid'])) {
            return [(int) $params['parentUid']];
        }

        if (!is_array($pageIds) || [] === $pageIds) {
            return null;
        }

        return array_values(array_map(static fn ($value): int => (int) $value, $pageIds));
    }

    /**
     * @param list<int> $pageIds
     *
     * @return list<int>
     */
    private function collectByPages(array $pageIds, string $childTable): array
    {
        if (count($pageIds) > self::MAX_PAGES) {
            throw new InvalidParameterException(sprintf(
                'At most %d pages per call, %d given. Split the run, so a partial rollout stays visible.',
                self::MAX_PAGES,
                count($pageIds),
            ));
        }

        $total = 0;
        foreach ($pageIds as $pageId) {
            $this->recordAccess->assertPagePerm($pageId, Permission::PAGE_SHOW);
            $total += $this->recordRepository->countRecordsOnPage($childTable, $pageId);
        }
        $this->assertWithinBudget($total, sprintf('%s records on %d page(s)', $childTable, count($pageIds)));

        $uids = [];
        foreach ($pageIds as $pageId) {
            $uids = array_merge($uids, $this->recordRepository->findUidsByCriteria(
                $childTable,
                $pageId,
                [],
                null,
                null,
                'uid',
                $this->recordBudget(),
                0,
            ));
        }

        return array_values(array_unique($uids));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function nothingFoundMessage(array $params, string $childTable): string
    {
        $pageIds = $this->resolvePageIds($params);
        if (null !== $pageIds) {
            return sprintf('No %s records found on page(s) %s.', $childTable, implode(', ', $pageIds));
        }

        return sprintf(
            'No %s records found with %s = %d.',
            $childTable,
            (string) ($params['relationField'] ?? '?'),
            (int) ($params['parentUid'] ?? 0),
        );
    }

    private function prefixDryRun(CallToolResult $result): CallToolResult
    {
        $first = $result->content[0] ?? null;
        $text = $first instanceof TextContent ? $first->text : '';

        return new CallToolResult(
            [new TextContent("## DRY RUN — nothing was written\n\n".$text)],
            isError: $result->isError,
            structuredContent: $result->structuredContent,
        );
    }
}
