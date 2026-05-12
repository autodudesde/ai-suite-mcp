<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Audit;

use AutoDudes\AiSuiteMcp\Mcp\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\ContentAuditService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Analyze pages for SEO issues, missing translations, missing alt texts,
 * and accessibility problems. Returns structured report with severity levels
 * and suggestions referencing AI Suite tools.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class AuditContentTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly ContentAuditService $contentAuditService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'auditContent';
    }

    public function getDescription(): string
    {
        return 'Analyze pages for SEO issues, missing translations, missing alt texts, and accessibility problems. '
            .'Returns a structured report with severity levels. '
            .'Use the report to decide which tools to call next (e.g. generateMetadata, translatePage, generateFileMetadata). '
            .'Returns only pages within your backend webmounts; pages outside are silently excluded with a counter in the report.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'Page UID to audit'],
                'depth' => [
                    'type' => 'integer', 'default' => 0,
                    'description' => '0 = only this page, 1+ = include subpages',
                    'minimum' => 0, 'maximum' => 10,
                ],
                'checks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['seo', 'translations', 'accessibility', 'images'],
                    ],
                    'default' => ['seo', 'translations', 'accessibility', 'images'],
                    'description' => 'Which checks to run',
                ],
                'targetLanguages' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'ISO codes to check translations for (empty = all site languages)',
                ],
                'limit' => [
                    'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500,
                    'description' => 'Maximum number of issues to return',
                ],
            ],
            'required' => ['pageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $this->assertPagePerm($pageId, Permission::PAGE_SHOW);

        $report = $this->contentAuditService->audit(
            $pageId,
            (int) ($params['depth'] ?? 0),
            $params['checks'] ?? ['seo', 'translations', 'accessibility', 'images'],
            $params['targetLanguages'] ?? [],
            (int) ($params['limit'] ?? 100),
        );

        // Extract deduplicated UIDs for easy piping into batch tools
        $affectedPageIds = [];
        $affectedFileUids = [];

        foreach ($report['issues'] ?? [] as $issue) {
            if (isset($issue['page']['uid'])) {
                $affectedPageIds[(int) $issue['page']['uid']] = true;
            }
            if (isset($issue['fileUid'])) {
                $affectedFileUids[(int) $issue['fileUid']] = true;
            }
        }

        $report['affectedPageIds'] = array_keys($affectedPageIds);
        $report['affectedFileUids'] = array_keys($affectedFileUids);

        $text = $this->formatReport($report);

        return new CallToolResult(
            content: [new TextContent($text)],
        );
    }

    /**
     * @param array<string, mixed> $report
     */
    private function formatReport(array $report): string
    {
        $summary = $report['summary'] ?? [];
        $text = sprintf(
            "## Audit Report: %d page(s) checked, %d issue(s) found\n\n",
            $summary['pagesChecked'] ?? 0,
            $summary['totalIssues'] ?? 0,
        );

        $bySeverity = $summary['bySeverity'] ?? [];
        if (!empty($bySeverity)) {
            $parts = [];
            foreach ($bySeverity as $severity => $count) {
                if ($count > 0) {
                    $parts[] = sprintf('%s: %d', $severity, $count);
                }
            }
            if (!empty($parts)) {
                $text .= '**Severity:** '.implode(' | ', $parts)."\n\n";
            }
        }

        foreach ($report['issues'] ?? [] as $issue) {
            $severity = strtoupper($issue['severity'] ?? 'info');
            $page = $issue['page'] ?? [];
            $pageInfo = isset($page['uid']) ? sprintf(' (Page %d: %s)', $page['uid'], $page['title'] ?? '') : '';
            $text .= sprintf("- **[%s]** %s — %s%s\n", $severity, $issue['field'] ?? '', $issue['message'] ?? '', $pageInfo);
            if (!empty($issue['suggestion'])) {
                $text .= sprintf("  → %s\n", $issue['suggestion']);
            }
        }

        $affectedPageIds = $report['affectedPageIds'] ?? [];
        $affectedFileUids = $report['affectedFileUids'] ?? [];
        if (!empty($affectedPageIds) || !empty($affectedFileUids)) {
            $text .= "\n## Quick-fix UIDs\n";
            if (!empty($affectedPageIds)) {
                $text .= 'Pages needing attention: '.json_encode($affectedPageIds)."\n";
            }
            if (!empty($affectedFileUids)) {
                $text .= 'Files needing metadata: '.json_encode($affectedFileUids)."\n";
            }
        }

        return $text;
    }
}
