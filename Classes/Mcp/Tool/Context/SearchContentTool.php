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

class SearchContentTool extends AbstractTool
{
    private const OWN_HANDLERS = ['pages', 'tt_content'];

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
                'searchIn' => ['type' => 'string', 'enum' => ['all', 'pages', 'content'], 'default' => 'all', 'description' => 'Where to search.'],
                'rootPageId' => ['type' => 'integer', 'description' => 'Subtree root page UID: restrict the search to this page and everything below it. Otherwise all pages within your webmounts.'],
                'field' => ['type' => 'string', 'description' => 'Restrict the content search to one tt_content column (e.g. bodytext, header, subheader). Child tables are searched in that column too, and skipped when they lack it. Otherwise all text-bearing fields of each table.'],
                'matchHtml' => ['type' => 'boolean', 'default' => false, 'description' => 'Keep HTML markup in the bodytext preview (so <a>, class names etc. stay visible). Stripped otherwise.'],
                'includeFullContent' => ['type' => 'boolean', 'default' => false, 'description' => 'Return full content text instead of preview snippets. '
                    .'This pulls every hit\'s full text into the conversation, where it stays. '
                    .'The snippets are there to pick the right hit — then read that one record fully.'],
                'limit' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'description' => 'Maximum number of results.'],
                'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Skip first N results for pagination.'],
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
            if (!$this->recordAccess->canAccessField('tt_content', $field)) {
                return $this->textError(sprintf('The tt_content field "%s" is not readable for you, so it cannot be searched.', $field));
            }
            if ('pages' === $searchIn) {
                return $this->textError('"field" restricts the content search, so it cannot be combined with searchIn "pages". '
                    .'Omit "field" to search pages, or use searchIn "content".');
            }
            $fieldRestricted = true;
        }

        $contentFields = $fieldRestricted
            ? [$field]
            : $this->readableFields('tt_content', $this->tcaCompatibilityService->getSearchableTextFields('tt_content'));

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

        if (('all' === $searchIn || 'pages' === $searchIn) && !$fieldRestricted && $this->recordAccess->hasTableReadAccess('pages')) {
            $results = array_merge($results, $this->searchPages($query, $allowedPageIds));
            $searchedTables[] = 'pages';
        }
        if ('all' === $searchIn || 'content' === $searchIn) {
            if ($this->recordAccess->hasTableReadAccess('tt_content') && [] !== $contentFields) {
                $results = array_merge($results, $this->searchContentElements($query, $includeFullContent, $allowedPageIds, $contentFields, $matchHtml));
                $searchedTables[] = 'tt_content';
            }

            foreach ($this->searchableTables->getSearchableTables(self::OWN_HANDLERS) as $table) {
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
                .'with readRecords and a filter. A table is left out when it is not readable for you, has no '
                .'text field, or is listed in the ai_suite_mcp setting "Excluded tables".',
                implode(', ', $searchedTables),
            );
        }
        if ([] === $this->searchableTables->getSearchableTables(self::OWN_HANDLERS)) {
            $notes[] = 'Only pages and content elements are searchable here. Every other record table that lives on '
                .'pages and carries text (accordion items, cards, news) is detected from the TCA automatically, but '
                .'none were found here — either this installation has none, or they are listed in the ai_suite_mcp '
                .'setting "Excluded tables".';
        }
        if ([] !== $notes) {
            $payload['note'] = implode(' ', $notes);
        }

        return $this->withFoundRecords(new CallToolResult([new TextContent((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ))]), $this->foundRecordsOf($results));
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return list<array{table: string, uid: int}>
     */
    private function foundRecordsOf(array $results): array
    {
        $found = [];
        foreach ($results as $result) {
            $table = (string) ($result['matchIn'] ?? '');
            $uid = (int) ($result['uid'] ?? 0);
            if ('' !== $table && $uid > 0) {
                $found[] = ['table' => $table, 'uid' => $uid];
            }
        }

        return $found;
    }

    /**
     * @param null|list<int> $allowedPageIds
     *
     * @return list<array<string, mixed>>
     */
    private function searchPages(string $query, ?array $allowedPageIds): array
    {
        $fields = $this->readableFields('pages', $this->tcaCompatibilityService->getSearchableTextFields('pages'));
        if ([] === $fields) {
            return [];
        }
        $rows = $this->workspaceRecords->overlayRows(
            'pages',
            $this->workspaceRecords->foldVersionsOntoLive($this->pagesRepository->searchByText($query, 100, $allowedPageIds, $fields)),
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
        $fields = $this->readableFields($table, $this->tcaCompatibilityService->getSearchableTextFields($table));
        if (null !== $onlyField) {
            $fields = in_array($onlyField, $fields, true) ? [$onlyField] : [];
        }
        if ([] === $fields) {
            return [];
        }

        $rows = $this->workspaceRecords->overlayRows(
            $table,
            $this->workspaceRecords->foldVersionsOntoLive($this->recordRepository->searchByText(
                $table,
                $query,
                $fields,
                $allowedPageIds,
                100,
                $this->tcaCompatibilityService->isWorkspaceAware($table),
                $this->languageFieldName($table),
            )),
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
                'label' => ($this->recordAccess->canAccessField($table, $labelField) ? (string) ($row[$labelField] ?? '') : '') ?: '(no label)',
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
            $this->workspaceRecords->foldVersionsOntoLive($this->contentRepository->searchByText($query, 100, $allowedPageIds, $searchFields)),
        );

        $bodyReadable = $this->recordAccess->canAccessField('tt_content', 'bodytext');
        $headerReadable = $this->recordAccess->canAccessField('tt_content', 'header');
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
                'header' => $headerReadable ? $row['header'] : '',
                'CType' => $row['CType'], 'matchIn' => 'tt_content',
                'matchedField' => $matchedField,
            ] + $this->languageKeys($pageId, $row);
            if ($bodyReadable && $full) {
                $element['bodytext'] = $body;
            } elseif ($bodyReadable) {
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

    /**
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private function readableFields(string $table, array $fields): array
    {
        return array_values(array_filter($fields, fn (string $field): bool => $this->recordAccess->canAccessField($table, $field)));
    }
}
