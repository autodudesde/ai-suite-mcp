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
        return 'Full SEO audit for one page, named by pageId — the answer to "check page 12 '
            .'for SEO problems". On-page checks, Lighthouse scores with real-user Core Web Vitals, '
            .'GEO/AI-visibility signals. A focus keyword adds SERP position, top-10 competitors and '
            .'search volume. Issues come back prioritized. Costs 3 credits.';
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
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Optional focus keyword — adds SERP position, top-10 competition and search volume.',
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
        $keyword = trim((string) ($params['keyword'] ?? ''));
        if (mb_strlen($keyword) > 200) {
            return $this->textError('keyword exceeds the maximum length of 200 characters.');
        }

        $data = ['url' => $url];
        if ('' !== $keyword) {
            $data['keyword'] = $keyword;
        }
        $body = $this->sendAiRequest('seoAudit', $data);

        return $this->auditResult('seo', $url, $keyword, $this->summarize($body, $keyword), $body);
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
