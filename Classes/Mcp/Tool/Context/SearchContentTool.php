<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\SearchableTablesService;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceRecordService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class SearchContentTool extends AbstractTool
{
    private const PREVIEW_LENGTH = 200;
    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly PagesRepository $pagesRepository,
        private readonly ContentRepository $contentRepository,
        private readonly RecordRepository $recordRepository,
        private readonly WorkspaceRecordService $workspaceRecords,
        private readonly SearchableTablesService $searchableTables,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'searchContent';
    }

    public function getDescription(): string
    {
        return 'Full-text search across pages, content elements and IRRE child tables (accordion items, cards), '
            .'which are detected automatically — `searchedTables` names every table that was searched. '
            .'Returns matching results with page context and content previews, '
            .'only for items within your backend webmounts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search term'],
                'searchIn' => ['type' => 'string', 'enum' => ['all', 'pages', 'content'], 'default' => 'all', 'description' => 'Where to search. Default: all.'],
                'rootPageId' => ['type' => 'integer', 'description' => 'Subtree root page UID: restrict the search to this page and everything below it. Default: all pages within your webmounts.'],
                'field' => ['type' => 'string', 'description' => 'Restrict the content search to a single column name, given as a tt_content field (e.g. bodytext, header, subheader). Child tables are then searched in that column too, and skipped when they do not have it. Default: all text-bearing fields of each table.'],
                'matchHtml' => ['type' => 'boolean', 'default' => false, 'description' => 'Keep HTML markup in the bodytext preview (so <a>, class names etc. are visible/searchable). Default: false (stripped).'],
                'includeFullContent' => ['type' => 'boolean', 'default' => false, 'description' => 'Return full content text instead of preview snippets. Default: false. '
                    .'Expensive: every hit\'s full text enters the conversation and is paid for in this and every later turn. '
                    .'The snippets are there to decide which hit is the right one — read that one record fully instead.'],
                'limit' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'description' => 'Maximum number of results. Default: 20.'],
                'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Skip first N results for pagination. Default: 0.'],
            ],
            'required' => ['query'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $query = (string) $params['query'];
        $searchIn = $params['searchIn'] ?? 'all';
        $limit = (int) ($params['limit'] ?? 20);
        $offset = (int) ($params['offset'] ?? 0);
        $matchHtml = (bool) ($params['matchHtml'] ?? false);

        $fieldRestricted = false;
        $field = (string) ($params['field'] ?? '');
        if ('' !== $field) {
            if (!$this->recordAccess->fieldExistsInSchema('tt_content', $field)) {
                return $this->textError(sprintf('Unknown tt_content field "%s".', $field));
            }
            $fieldRestricted = true;
        }

        $contentFields = $fieldRestricted
            ? [$field]
            : $this->tcaCompatibilityService->getSearchableTextFields('tt_content');

        $includeFullContent = (bool) ($params['includeFullContent'] ?? false);
        if ($includeFullContent && !$this->userContext->hasScope('mcp:generate') && !$this->userContext->hasScope('mcp:translate')) {
            $includeFullContent = false;
        }

        $beUser = $this->getBackendUser();
        $allowedPageIds = (null === $beUser || $beUser->isAdmin())
            ? null
            : $this->recordAccess->getReadablePageIds();

        $rootPageId = (int) ($params['rootPageId'] ?? 0);
        if ($rootPageId > 0) {
            $subtree = $this->recordAccess->getReadablePageIds($rootPageId);
            $allowedPageIds = (null === $allowedPageIds)
                ? $subtree
                : array_values(array_intersect($allowedPageIds, $subtree));

            if ([] === $allowedPageIds) {
                return $this->textError(sprintf(
                    'Page %d does not exist or is not readable for you, so the search has no scope. '
                    .'Use readPageTree to find a page you can reach, or omit rootPageId to search all your webmounts.',
                    $rootPageId,
                ));
            }
        }

        $results = [];
        $searchedTables = [];

        if (('all' === $searchIn || 'pages' === $searchIn) && !$fieldRestricted) {
            $results = array_merge($results, $this->searchPages($query, $allowedPageIds));
            $searchedTables[] = 'pages';
        }
        if ('all' === $searchIn || 'content' === $searchIn) {
            $results = array_merge($results, $this->searchContentElements($query, $includeFullContent, $allowedPageIds, $contentFields, $matchHtml));
            $searchedTables[] = 'tt_content';

            foreach ($this->searchableTables->getAdditionalTables() as $table) {
                try {
                    $this->recordAccess->validateTableReadAccess($table);
                } catch (\Throwable) {
                    // Not readable for this user: silently out of scope, not an error.
                    continue;
                }

                if ($fieldRestricted && !$this->recordAccess->fieldExistsInSchema($table, $field)) {
                    continue;
                }

                try {
                    $tableResults = $this->searchAdditionalTable($table, $query, $allowedPageIds, $matchHtml, $fieldRestricted ? $field : null);
                } catch (\Throwable $e) {
                    $this->logger->warning('Additional table sweep failed', ['table' => $table, 'error' => $e->getMessage()]);

                    continue;
                }

                $results = array_merge($results, $tableResults);
                $searchedTables[] = $table;
            }
        }

        $total = count($results);
        $results = array_slice($results, $offset, $limit);

        $payload = [
            'results' => $results,
            'searchedTables' => $searchedTables,
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'hasMore' => ($offset + $limit) < $total],
        ];
        $notes = [];
        if ([] === $results) {
            $notes[] = sprintf(
                'Nothing matched in the tables that were searched: %s. A record type outside that list was '
                .'not looked at, so this is not evidence that it does not exist — read the table directly '
                .'with readRecords and a filter, or have an administrator add it to the ai_suite_mcp '
                .'setting "Additional Searchable Tables".',
                implode(', ', $searchedTables),
            );
        }
        if ([] === $this->searchableTables->getAdditionalTables()) {
            $notes[] = 'Only pages and content elements are searchable here. Child-record tables (accordion items, '
                .'card group cards) are detected from the TCA automatically, but none were found here — either this '
                .'installation has none, or they are listed in the ai_suite_mcp settings "Exclude Auto-Detected Tables '
                .'from Search" / "MCP Excluded Tables". Standalone record tables such as tx_news_domain_model_news are '
                .'not child tables and have to be added under "Additional Searchable Tables".';
        }
        if ([] !== $notes) {
            $payload['note'] = implode(' ', $notes);
        }

        return new CallToolResult([new TextContent((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ))]);
    }

    /**
     * @param null|list<int> $allowedPageIds
     *
     * @return list<array<string, mixed>>
     */
    private function searchPages(string $query, ?array $allowedPageIds): array
    {
        $fields = $this->tcaCompatibilityService->getSearchableTextFields('pages');
        $rows = $this->workspaceRecords->overlayRows(
            'pages',
            $this->pagesRepository->searchByText($query, 100, $allowedPageIds, $fields),
        );

        $results = [];
        foreach ($rows as $row) {
            $matchedField = $this->matchedField($row, $fields, $query);
            if (null === $matchedField) {
                continue;
            }

            $uid = (int) $row['uid'];
            $results[] = [
                'type' => 'page', 'uid' => $uid, 'title' => $row['title'],
                'slug' => $row['slug'], 'matchIn' => 'pages', 'matchedField' => $matchedField,
            ] + $this->languageKeys($uid, $row);
        }

        return $results;
    }

    /**
     * @param null|list<int> $allowedPageIds
     *
     * @return list<array<string, mixed>>
     */
    private function searchAdditionalTable(string $table, string $query, ?array $allowedPageIds, bool $matchHtml, ?string $onlyField): array
    {
        $fields = $this->tcaCompatibilityService->getSearchableTextFields($table);
        if (null !== $onlyField) {
            $fields = in_array($onlyField, $fields, true) ? [$onlyField] : [];
        }
        if ([] === $fields) {
            return [];
        }

        $rows = $this->workspaceRecords->overlayRows(
            $table,
            $this->recordRepository->searchByText(
                $table,
                $query,
                $fields,
                $allowedPageIds,
                100,
                $this->tcaCompatibilityService->isWorkspaceAware($table),
                $this->languageFieldName($table),
            ),
        );

        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $results = [];
        foreach ($rows as $row) {
            $matchedField = $this->matchedField($row, $fields, $query);
            if (null === $matchedField) {
                continue;
            }

            $value = (string) ($row[$matchedField] ?? '');
            $pageId = (int) ($row['pid'] ?? 0);
            $results[] = [
                'type' => 'record',
                'uid' => (int) $row['uid'],
                'pageId' => $pageId,
                'label' => (string) ($row[$labelField] ?? '') ?: '(no label)',
                'matchIn' => $table,
                'matchedField' => $matchedField,
                'preview' => mb_substr($matchHtml ? $value : strip_tags($value), 0, self::PREVIEW_LENGTH),
            ] + $this->languageKeys($pageId, $row, $this->languageFieldName($table));
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function languageKeys(int $pageId, array $row, ?string $languageField = 'sys_language_uid'): array
    {
        if (null === $languageField || '' === $languageField || !array_key_exists($languageField, $row)) {
            return [];
        }

        $languageUid = (int) $row[$languageField];
        $keys = ['languageUid' => $languageUid];

        $iso = $this->siteLanguages->getIsoCodeForLanguageUid($pageId, $languageUid);
        if (null !== $iso) {
            $keys['language'] = $iso;
        }

        return $keys;
    }

    private function languageFieldName(string $table): ?string
    {
        try {
            return $this->tcaCompatibilityService->isLanguageAware($table)
                ? $this->tcaCompatibilityService->getLanguageFieldName($table)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $fields
     */
    private function matchedField(array $row, array $fields, string $query): ?string
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            if (false !== mb_stripos((string) $row[$field], $query)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param null|list<int> $allowedPageIds
     * @param list<string>   $searchFields
     *
     * @return list<array<string, mixed>>
     */
    private function searchContentElements(string $query, bool $full, ?array $allowedPageIds, array $searchFields, bool $matchHtml = false): array
    {
        $rows = $this->workspaceRecords->overlayRows(
            'tt_content',
            $this->contentRepository->searchByText($query, 100, $allowedPageIds, $searchFields),
        );

        $results = [];
        foreach ($rows as $row) {
            $matchedField = $this->matchedField($row, $searchFields, $query);
            if (null === $matchedField) {
                continue;
            }

            $body = $matchHtml ? (string) $row['bodytext'] : strip_tags((string) $row['bodytext']);
            $pageId = (int) $row['pid'];
            $element = [
                'type' => 'content', 'uid' => (int) $row['uid'], 'pageId' => $pageId,
                'header' => $row['header'], 'CType' => $row['CType'], 'matchIn' => 'tt_content',
                'matchedField' => $matchedField,
            ] + $this->languageKeys($pageId, $row);
            if ($full) {
                $element['bodytext'] = $body;
            } else {
                $element['bodytext_preview'] = mb_substr($body, 0, self::PREVIEW_LENGTH);
                $element['bodytext_length'] = mb_strlen($body);
            }

            if ('bodytext' !== $matchedField && 'header' !== $matchedField) {
                $matchedValue = (string) ($row[$matchedField] ?? '');
                $element['matchedPreview'] = mb_substr($matchHtml ? $matchedValue : strip_tags($matchedValue), 0, self::PREVIEW_LENGTH);
            }

            $results[] = $element;
        }

        return $results;
    }
}
