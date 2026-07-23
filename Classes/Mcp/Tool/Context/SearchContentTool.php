<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
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

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly PagesRepository $pagesRepository,
        private readonly ContentRepository $contentRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'searchContent';
    }

    public function getDescription(): string
    {
        return 'Full-text search across pages and content elements. Returns matching results '
            .'with page context and content previews. '
            .'Pass `field` to search a single tt_content field (e.g. bodytext), and `matchHtml` to search/return the raw HTML markup. '
            .'Returns only items within your backend webmounts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search term'],
                'searchIn' => ['type' => 'string', 'enum' => ['all', 'pages', 'content'], 'default' => 'all', 'description' => 'Where to search. Default: all.'],
                'field' => ['type' => 'string', 'description' => 'Restrict the content search to a single tt_content field (e.g. bodytext, header, subheader). Default: all text-bearing fields of the table.'],
                'matchHtml' => ['type' => 'boolean', 'default' => false, 'description' => 'Keep HTML markup in the bodytext preview (so <a>, class names etc. are visible/searchable). Default: false (stripped).'],
                'includeFullContent' => ['type' => 'boolean', 'default' => false, 'description' => 'Return full content text instead of preview snippets. Default: false.'],
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

        // Default: search every text-bearing tt_content column discovered from TCA
        // (header, subheader, bodytext, …) — no hardcoded field list.
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

        $results = [];

        // A specific tt_content field restricts the search to content only (pages have
        // a different schema), so only sweep pages when the caller did not narrow to one.
        if (('all' === $searchIn || 'pages' === $searchIn) && !$fieldRestricted) {
            $results = array_merge($results, $this->searchPages($query, $allowedPageIds));
        }
        if ('all' === $searchIn || 'content' === $searchIn) {
            $results = array_merge($results, $this->searchContentElements($query, $includeFullContent, $allowedPageIds, $contentFields, $matchHtml));
        }

        $total = count($results);
        $results = array_slice($results, $offset, $limit);

        return new CallToolResult([new TextContent((string) json_encode([
            'results' => $results,
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'hasMore' => ($offset + $limit) < $total],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))]);
    }

    /**
     * @param null|list<int> $allowedPageIds
     *
     * @return list<array<string, mixed>>
     */
    private function searchPages(string $query, ?array $allowedPageIds): array
    {
        $rows = $this->pagesRepository->searchByText(
            $query,
            100,
            $allowedPageIds,
            $this->tcaCompatibilityService->getSearchableTextFields('pages'),
        );

        return array_map(fn ($r) => [
            'type' => 'page', 'uid' => (int) $r['uid'], 'title' => $r['title'],
            'slug' => $r['slug'], 'matchIn' => 'pages',
        ], $rows);
    }

    /**
     * @param null|list<int> $allowedPageIds
     * @param list<string>   $searchFields   text columns to match (from TCA discovery or the `field` param)
     *
     * @return list<array<string, mixed>>
     */
    private function searchContentElements(string $query, bool $full, ?array $allowedPageIds, array $searchFields, bool $matchHtml = false): array
    {
        $rows = $this->contentRepository->searchByText($query, 100, $allowedPageIds, $searchFields);

        return array_map(function ($r) use ($full, $matchHtml) {
            $body = $matchHtml ? (string) $r['bodytext'] : strip_tags((string) $r['bodytext']);
            $element = [
                'type' => 'content', 'uid' => (int) $r['uid'], 'pageId' => (int) $r['pid'],
                'header' => $r['header'], 'CType' => $r['CType'], 'matchIn' => 'tt_content',
            ];
            if ($full) {
                $element['bodytext'] = $body;
            } else {
                $element['bodytext_preview'] = mb_substr($body, 0, self::PREVIEW_LENGTH);
                $element['bodytext_length'] = mb_strlen($body);
            }

            return $element;
        }, $rows);
    }
}
