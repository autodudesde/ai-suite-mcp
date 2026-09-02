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
        return 'Run a WCAG 2.1 AA accessibility audit for one public page URL (axe-core + '
            .'HTML_CodeSniffer via pa11y). Returns error/warning/notice counts, top issue groups '
            .'with impact and sample selectors, and prioritized issues with fixability levels.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Absolute, publicly reachable URL of the page to audit.',
                ],
            ],
            'required' => ['url'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $url = trim((string) ($params['url'] ?? ''));
        // FILTER_VALIDATE_URL accepts any scheme; TYPO3-internal links (t3://...) cannot be audited
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            return $this->textError('url must be an absolute http(s) URL (e.g. https://example.com/page). TYPO3-internal links like t3://page?uid=1 cannot be audited - resolve the public URL of the page first.');
        }

        $data = ['url' => $url];
        $standard = $this->configuredWcagStandard();
        if ('' !== $standard) {
            $data['standard'] = $standard;
        }
        $body = $this->sendAiRequest('/accessibilityAudit', $data);

        return $this->structuredResult($this->summarize($body), $body);
    }

    // best effort: unit tests and early boot have no extension configuration
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
            $lines[] = 'Page is NOT publicly reachable — audit could not run (see issues).';
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
