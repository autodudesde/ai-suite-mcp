<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Seo;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class CompetitorAuditTool extends AbstractAuditAnalysisTool
{
    protected ?int $creditCost = 3;

    public function getName(): string
    {
        return 'auditCompetitors';
    }

    public function getDescription(): string
    {
        return 'Analyse the competitors of one public page URL\'s domain: top competitors with '
            .'shared keywords and estimated traffic, plus the keyword gap against the strongest '
            .'one (they rank, this domain does not), each gap keyword rated '
            .'answered/partial/open against the page content. Costs 3 credits.';
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
            '/competitorAudit',
            ['url' => $url, 'request_content' => $content, 'market' => $market],
            ['text' => $model],
            $this->normalizedLanguage($params),
        );

        return $this->structuredResult($this->summarize($body), $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarize(array $body): string
    {
        $lines = ['## Competitors: '.(string) ($body['target'] ?? '')];
        foreach (is_array($body['competitors'] ?? null) ? $body['competitors'] : [] as $competitor) {
            if (!is_array($competitor)) {
                continue;
            }
            $lines[] = sprintf(
                '- %s (%d shared keywords, %d top-10 rankings, est. traffic %d/month)',
                (string) ($competitor['domain'] ?? ''),
                (int) ($competitor['sharedKeywords'] ?? 0),
                (int) ($competitor['top10'] ?? 0),
                (int) ($competitor['etv'] ?? 0),
            );
        }

        if (true === ($body['domainUnknown'] ?? false)) {
            $lines[] = 'No ranking data exists for this domain yet (it may be new or not indexed), so no competitor could be determined. This is NOT a positive result — start with the SEO audit and indexable content.';

            return implode("\n", $lines);
        }
        if (true === ($body['noCandidates'] ?? false)) {
            $lines[] = 'No keyword gap found against the closest competitor — the page covers this market well.';

            return implode("\n", $lines);
        }

        $lines[] = '## Keyword gap vs. '.(string) ($body['gapTarget'] ?? '');
        $lines[] = self::coverageLine(is_array($body['summary'] ?? null) ? $body['summary'] : []);
        foreach (is_array($body['gaps'] ?? null) ? $body['gaps'] : [] as $gap) {
            if (!is_array($gap)) {
                continue;
            }
            $lines[] = sprintf(
                '- [%s] "%s" (%s, competitor at position %d) — %s',
                (string) ($gap['status'] ?? ''),
                (string) ($gap['keyword'] ?? ''),
                self::volumeLabel($gap['searchVolume'] ?? null, $gap['difficulty'] ?? null),
                (int) ($gap['competitorPosition'] ?? 0),
                (string) ($gap['note'] ?? ''),
            );
        }
        $lines[] = 'Open keywords are candidates for new topic sections or their own subpages.';

        return implode("\n", $lines);
    }
}
