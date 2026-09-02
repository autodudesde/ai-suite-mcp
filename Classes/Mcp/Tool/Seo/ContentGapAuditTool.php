<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Seo;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ContentGapAuditTool extends AbstractAuditAnalysisTool
{
    protected ?int $creditCost = 3;

    public function getName(): string
    {
        return 'auditContentGap';
    }

    public function getDescription(): string
    {
        return 'Find the content gap of one public page URL: keywords the page already ranks for '
            .'but too weakly to get traffic (public ranking data with search volume, position and '
            .'difficulty), each rated answered/partial/open against the page content. Costs 3 credits.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['url' => self::urlSchemaProperty()] + self::commonSchemaProperties(),
            'required' => ['url'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $url = $this->validatedUrl($params);
        if ($url instanceof CallToolResult) {
            return $url;
        }
        $content = $this->fetchAnalysisContent($url);
        if ($content instanceof CallToolResult) {
            return $content;
        }
        $model = $this->resolveTextModel($params);
        if ($model instanceof CallToolResult) {
            return $model;
        }
        $market = $this->resolveMarket($params, $url);
        if ($market instanceof CallToolResult) {
            return $market;
        }

        $body = $this->sendAiRequest(
            '/contentGapAudit',
            ['url' => $url, 'request_content' => $content, 'market' => $market],
            ['text' => $model],
            $this->normalizedLanguage($params),
        );

        return $this->structuredResult($this->summarize($body, $url), $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarize(array $body, string $url): string
    {
        $lines = ['## Content gap: '.$url];
        if (true === ($body['noCandidates'] ?? false)) {
            if (true === ($body['domainUnknown'] ?? false)) {
                $lines[] = 'No ranking data exists for this domain yet (it may be new or not indexed). This is NOT a positive result — the content gap analysis needs existing rankings; start with the SEO audit and indexable content.';
            } elseif (true === ($body['pageNotRanking'] ?? false)) {
                $lines[] = 'The domain has rankings, but this specific page has none yet — so there is no per-keyword gap to analyse. Consider an SEO audit of this page first.';
            } else {
                $lines[] = 'No underperforming rankings found — this page converts its visibility well.';
            }

            return implode("\n", $lines);
        }

        $lines[] = self::coverageLine(is_array($body['summary'] ?? null) ? $body['summary'] : []);
        foreach (is_array($body['gaps'] ?? null) ? $body['gaps'] : [] as $gap) {
            if (!is_array($gap)) {
                continue;
            }
            $lines[] = sprintf(
                '- [%s] "%s" (%s, currently position %d) — %s',
                (string) ($gap['status'] ?? ''),
                (string) ($gap['keyword'] ?? ''),
                self::volumeLabel($gap['searchVolume'] ?? null, $gap['difficulty'] ?? null),
                (int) ($gap['position'] ?? 0),
                (string) ($gap['note'] ?? ''),
            );
        }
        $lines[] = 'Open and partial keywords are candidates for new topic sections on the page.';

        return implode("\n", $lines);
    }
}
