<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Seo;

use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class SeoAuditTool extends AbstractAiTool
{
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;
    protected ?int $creditCost = 3;

    public function getName(): string
    {
        return 'auditSeo';
    }

    public function getDescription(): string
    {
        return 'Run a full SEO audit for one public page URL: technical on-page checks, '
            .'Lighthouse scores with real-user Core Web Vitals, and GEO/AI-visibility signals. '
            .'Pass a focus keyword to add SERP position, top-10 competitors and search volume. '
            .'Issues return prioritized with fixability levels.';
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
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Optional focus keyword — adds SERP position, top-10 competition and search volume.',
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
        $keyword = trim((string) ($params['keyword'] ?? ''));
        if (mb_strlen($keyword) > 200) {
            return $this->textError('keyword exceeds the maximum length of 200 characters.');
        }

        $data = ['url' => $url];
        if ('' !== $keyword) {
            $data['keyword'] = $keyword;
        }
        $body = $this->sendAiRequest('/seoAudit', $data);

        return $this->structuredResult($this->summarize($body, $keyword), $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarize(array $body, string $keyword): string
    {
        $audit = is_array($body['audit'] ?? null) ? $body['audit'] : [];
        $lines = ['## SEO audit: '.(string) ($audit['url'] ?? '')];

        if (false === ($audit['reachable'] ?? true)) {
            $lines[] = 'Page is NOT publicly reachable — audit could not run (see issues).';
        }

        $summary = $audit['summary'] ?? [];
        $lines[] = sprintf(
            '- Issues: %d errors, %d warnings, %d notices',
            (int) ($summary['errors'] ?? 0),
            (int) ($summary['warnings'] ?? 0),
            (int) ($summary['notices'] ?? 0),
        );
        $scores = $audit['performance']['scores'] ?? null;
        if (is_array($scores) && [] !== $scores) {
            $lines[] = '- Lighthouse: '.implode(', ', array_map(
                static fn (string $k, mixed $v): string => sprintf('%s %d', $k, (int) round((float) $v)),
                array_keys($scores),
                array_values($scores),
            ));
        }

        foreach (array_slice(is_array($audit['issues'] ?? null) ? $audit['issues'] : [], 0, 12) as $issue) {
            $lines[] = sprintf(
                '- [%s/%s] %s: %s',
                (string) ($issue['severity'] ?? ''),
                (string) ($issue['fixability'] ?? ''),
                (string) ($issue['id'] ?? ''),
                (string) ($issue['message'] ?? ''),
            );
        }

        $market = is_array($body['keyword'] ?? null) ? $body['keyword'] : null;
        if (null !== $market) {
            $volume = $market['volume']['searchVolume'] ?? null;
            $position = $market['serp']['ownPosition'] ?? null;
            $lines[] = sprintf(
                '## Market view "%s": %s searches/month, own position: %s, top-10 avg title length: %s',
                $keyword,
                null === $volume ? 'unknown' : (string) $volume,
                null === $position ? 'not in SERP (depth 20)' : '#'.$position,
                (string) ($market['insights']['avgTitleLength'] ?? '-'),
            );
        }

        return implode("\n", $lines);
    }
}
