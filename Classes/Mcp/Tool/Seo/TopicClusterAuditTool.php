<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Seo;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class TopicClusterAuditTool extends AbstractAuditAnalysisTool
{
    protected ?int $creditCost = 3;

    public function getName(): string
    {
        return 'auditTopicCluster';
    }

    public function getDescription(): string
    {
        return 'Analyse the topic clusters (query fan-out) around a focus keyword for one public '
            .'page URL: the market\'s subtopics with search volume and difficulty, each rated '
            .'answered/partial/open against the page content. Costs 3 credits.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => self::urlSchemaProperty(),
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Focus keyword to explore the topic around (required).',
                ],
            ] + self::commonSchemaProperties(),
            'required' => ['url', 'keyword'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $url = $this->validatedUrl($params);
        if ($url instanceof CallToolResult) {
            return $url;
        }
        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ('' === $keyword || mb_strlen($keyword) > 200) {
            return $this->textError('keyword is required (max. 200 characters).');
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
            '/topicClusterAudit',
            ['keyword' => $keyword, 'request_content' => $content, 'market' => $market],
            ['text' => $model],
            $this->normalizedLanguage($params),
        );

        return $this->structuredResult($this->summarize($body, $keyword), $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarize(array $body, string $keyword): string
    {
        $lines = ['## Topic clusters: "'.$keyword.'"'];
        if (true === ($body['noClusters'] ?? false)) {
            $lines[] = 'No cluster data found for this keyword — try a broader focus keyword.';

            return implode("\n", $lines);
        }

        $lines[] = self::coverageLine(is_array($body['summary'] ?? null) ? $body['summary'] : []);
        foreach (is_array($body['clusters'] ?? null) ? $body['clusters'] : [] as $cluster) {
            if (!is_array($cluster)) {
                continue;
            }
            $keywords = array_column(
                array_slice(is_array($cluster['keywords'] ?? null) ? $cluster['keywords'] : [], 0, 3),
                'keyword',
            );
            $lines[] = sprintf(
                '- [%s] "%s" (%d queries, %d searches/month; e.g. %s) — %s',
                (string) ($cluster['status'] ?? ''),
                (string) ($cluster['label'] ?? ''),
                (int) ($cluster['keywordCount'] ?? 0),
                (int) ($cluster['totalSearchVolume'] ?? 0),
                implode(', ', array_map(static fn ($k): string => '"'.(string) $k.'"', $keywords)),
                (string) ($cluster['note'] ?? ''),
            );
        }
        $lines[] = 'Open clusters are candidates for new topic sections or their own subpages.';

        return implode("\n", $lines);
    }
}
