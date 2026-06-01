<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class FindStaleContentTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly PagesRepository $pagesRepository,
        private readonly ContentRepository $contentRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'findStaleContent';
    }

    public function getDescription(): string
    {
        return 'Find pages or records that have not been modified for a given number of days. '
            .'For pages: optionally checks content element timestamps too, so a page is only considered stale '
            .'if neither the page record nor any of its content elements were recently edited. '
            .'Returns only items within your backend webmounts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'olderThanDays' => [
                    'type' => 'integer',
                    'description' => 'Minimum number of days since last modification.',
                ],
                'table' => [
                    'type' => 'string',
                    'default' => 'pages',
                    'description' => 'TCA table to check (default: pages).',
                ],
                'rootPageId' => [
                    'type' => 'integer',
                    'description' => 'Restrict to this page subtree.',
                ],
                'includeContent' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'For pages: also check tt_content timestamps. A page is only stale if no content was edited either.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 200,
                ],
                'offset' => [
                    'type' => 'integer',
                    'default' => 0,
                    'minimum' => 0,
                ],
            ],
            'required' => ['olderThanDays'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $olderThanDays = (int) $params['olderThanDays'];
        $table = (string) ($params['table'] ?? 'pages');
        $rootPageId = $params['rootPageId'] ?? null;
        $includeContent = (bool) ($params['includeContent'] ?? true);
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);

        $this->recordAccess->validateTableReadAccess($table);

        $cutoff = time() - ($olderThanDays * 86400);
        $restrictToPageIds = null;

        if (null !== $rootPageId) {
            $this->recordAccess->assertPagePerm((int) $rootPageId, Permission::PAGE_SHOW);
            $restrictToPageIds = $this->pagesRepository->getSubtreePageIds((int) $rootPageId);
            if (empty($restrictToPageIds)) {
                return $this->textResult('No pages found in the specified subtree.');
            }
        } else {
            $beUser = $this->getBackendUser();
            if (null !== $beUser && !$beUser->isAdmin()) {
                try {
                    $restrictToPageIds = $this->recordAccess->getReadablePageIds(0, 99);
                } catch (InsufficientPermissionException $e) {
                    $this->logger->warning('FindStaleContentTool: webmount too large for global scan, asking user to scope by rootPageId', [
                        'beUserUid' => $this->getBackendUser()?->user['uid'] ?? null,
                        'reason' => $e->getMessage(),
                    ]);

                    return new CallToolResult(
                        [new TextContent('Webmount too large for global stale-content scan; please specify rootPageId to scope the search to a sub-tree.')],
                        isError: true,
                    );
                }
                if ([] === $restrictToPageIds) {
                    return $this->textResult('No accessible pages found in your webmounts.');
                }
            }
        }

        if ('pages' === $table && $includeContent) {
            $rows = $this->pagesRepository->findStalePages($cutoff, $restrictToPageIds, $limit, $offset);

            return $this->formatResult($rows, 'pages', $olderThanDays, $offset, true);
        }

        return $this->findStaleRecords($table, $cutoff, $restrictToPageIds, $limit, $offset, $olderThanDays);
    }

    /**
     * @param null|list<int> $restrictToPageIds
     */
    private function findStaleRecords(string $table, int $cutoff, ?array $restrictToPageIds, int $limit, int $offset, int $olderThanDays): CallToolResult
    {
        $rawConfig = $this->tcaCompatibilityService->getRawConfiguration($table);

        if (!isset($rawConfig['tstamp'])) {
            return new CallToolResult(
                [new TextContent(sprintf('Table "%s" has no tstamp field configured.', $table))],
                isError: true,
            );
        }

        $rows = $this->contentRepository->findStaleRecords(
            $table,
            $rawConfig['tstamp'],
            $this->tcaCompatibilityService->getLabelField($table),
            $cutoff,
            $restrictToPageIds,
            $limit,
            $offset,
        );

        return $this->formatResult($rows, $table, $olderThanDays, $offset, false);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function formatResult(array $rows, string $table, int $olderThanDays, int $offset, bool $withContent): CallToolResult
    {
        if (empty($rows)) {
            return new CallToolResult([new TextContent(
                sprintf('No %s records found that are older than %d days.', $table, $olderThanDays),
            )]);
        }

        $text = sprintf("## Stale %s (not modified in %d+ days)\n\n", $table, $olderThanDays);

        $rawConfig = $this->tcaCompatibilityService->getRawConfiguration($table);
        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $tstampField = $rawConfig['tstamp'] ?? 'tstamp';

        foreach ($rows as $row) {
            $uid = (int) $row['uid'];
            $label = $row[$labelField] ?? $row['title'] ?? '?';
            $lastActivity = (int) ($row['last_activity'] ?? $row[$tstampField] ?? 0);
            $daysAgo = $lastActivity > 0 ? (int) floor((time() - $lastActivity) / 86400) : 0;
            $date = $lastActivity > 0 ? date('Y-m-d', $lastActivity) : 'unknown';

            $text .= sprintf('- **%s** (UID: %d) — last modified: %s (%d days ago)', $label, $uid, $date, $daysAgo);

            if ($withContent && isset($row['page_tstamp'])) {
                $pageTstamp = (int) $row['page_tstamp'];
                if ($pageTstamp !== $lastActivity && $pageTstamp > 0) {
                    $text .= sprintf(' | page record: %s', date('Y-m-d', $pageTstamp));
                }
            }

            if (isset($row['slug'])) {
                $text .= sprintf(' | %s', $row['slug']);
            }

            $text .= "\n";
        }

        $text .= sprintf("\n_Showing %d results (offset: %d)._", count($rows), $offset);

        return $this->textResult($text);
    }
}
