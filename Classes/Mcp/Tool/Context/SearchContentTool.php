<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
use AutoDudes\AiSuiteMcp\Mcp\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class SearchContentTool extends AbstractTool
{
    private const PREVIEW_LENGTH = 200;
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        McpToolContext $mcpToolContext,
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
            .'Returns only items within your backend webmounts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search term'],
                'searchIn' => ['type' => 'string', 'enum' => ['all', 'pages', 'content'], 'default' => 'all', 'description' => 'Where to search. Default: all.'],
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

        $includeFullContent = (bool) ($params['includeFullContent'] ?? false);
        if ($includeFullContent && !$this->userContext->hasScope('mcp:generate') && !$this->userContext->hasScope('mcp:translate')) {
            $includeFullContent = false;
        }

        // Permission scope: admin → null = no filter; non-admin → webmount whitelist (pid IN …).
        // Empty list short-circuits the repository to an empty result.
        $beUser = $this->getBackendUser();
        $allowedPageIds = (null === $beUser || $beUser->isAdmin())
            ? null
            : $this->getReadablePageIds(0, 99);

        $results = [];

        if ('all' === $searchIn || 'pages' === $searchIn) {
            $results = array_merge($results, $this->searchPages($query, $allowedPageIds));
        }
        if ('all' === $searchIn || 'content' === $searchIn) {
            $results = array_merge($results, $this->searchContentElements($query, $includeFullContent, $allowedPageIds));
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
        $rows = $this->pagesRepository->searchByText($query, 100, $allowedPageIds);

        return array_map(fn ($r) => [
            'type' => 'page', 'uid' => (int) $r['uid'], 'title' => $r['title'],
            'slug' => $r['slug'], 'matchIn' => 'pages',
        ], $rows);
    }

    /**
     * @param null|list<int> $allowedPageIds
     *
     * @return list<array<string, mixed>>
     */
    private function searchContentElements(string $query, bool $full, ?array $allowedPageIds): array
    {
        $rows = $this->contentRepository->searchByText($query, 100, $allowedPageIds);

        return array_map(function ($r) use ($full) {
            $body = strip_tags((string) $r['bodytext']);
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
