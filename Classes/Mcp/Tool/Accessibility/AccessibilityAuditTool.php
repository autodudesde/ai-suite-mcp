<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Accessibility;

use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class AccessibilityAuditTool extends AbstractAiTool
{
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;
    protected ?int $creditCost = 3;

    public function getName(): string
    {
        return 'auditAccessibility';
    }

    public function getDescription(): string
    {
        return 'Run a WCAG 2.1 AA accessibility audit for one page, named by pageId — the answer to '
            .'"is page 12 accessible?" (axe-core + HTML_CodeSniffer via pa11y). Returns '
            .'error/warning/notice counts, top issue groups with impact and sample selectors, and '
            .'prioritized issues with fixability levels. Costs 3 credits.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => [
                    'type' => 'integer',
                    'description' => 'UID of the page to audit. Its public URL is resolved from the site configuration.',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'Absolute, publicly reachable URL — for a page outside this installation. Use pageId for one inside it.',
                ],
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $url = $this->resolveAuditUrl($params);
        if ($url instanceof CallToolResult) {
            return $url;
        }

        $data = ['url' => $url];
        $standard = $this->configuredWcagStandard();
        if ('' !== $standard) {
            $data['standard'] = $standard;
        }
        $body = $this->sendAiRequest('accessibilityAudit', $data);

        return $this->auditResult('a11y', $url, '', $this->summarize($body), $body);
    }

    private function configuredWcagStandard(): string
    {
        try {
            $standard = (string) (GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('ai_suite')['auditWcagStandard'] ?? '');
        } catch (\Throwable) {
            return '';
        }

        return \in_array($standard, ['WCAG2A', 'WCAG2AA', 'WCAG2AAA'], true) ? $standard : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarize(array $body): string
    {
        $audit = is_array($body['audit'] ?? null) ? $body['audit'] : [];
        $lines = ['## Accessibility audit: '.(string) ($audit['url'] ?? '')];

        if (false === ($audit['reachable'] ?? true)) {
            $lines[] = 'Page is not publicly reachable — audit could not run (see issues).';
        }

        $summary = $audit['a11y']['summary'] ?? [];
        $lines[] = sprintf(
            '- Scan result: %d errors, %d warnings, %d notices (%d distinct issue groups)',
            (int) ($summary['errors'] ?? 0),
            (int) ($summary['warnings'] ?? 0),
            (int) ($summary['notices'] ?? 0),
            (int) ($audit['a11y']['issueGroups'] ?? 0),
        );

        $topIssues = $audit['a11y']['topIssues'] ?? [];
        foreach (array_slice(is_array($topIssues) ? $topIssues : [], 0, 10) as $issue) {
            $lines[] = sprintf(
                '- %dx %s (%s): %s — e.g. `%s`',
                (int) ($issue['count'] ?? 0),
                (string) ($issue['code'] ?? ''),
                (string) ($issue['impact'] ?? $issue['type'] ?? ''),
                (string) ($issue['message'] ?? ''),
                (string) ($issue['sampleSelector'] ?? ''),
            );
        }

        return implode("\n", $lines);
    }
}
