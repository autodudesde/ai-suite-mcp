<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Seo;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ContentAuditTool extends AbstractAuditAnalysisTool
{
    // The type keys are the stored audit types readAuditResults reads back; do not rename them.
    private const ANALYSES = [
        'questions' => ['endpoint' => 'questionsAudit', 'keyword' => 'optional'],
        'gap' => ['endpoint' => 'contentGapAudit', 'keyword' => 'none'],
        'cluster' => ['endpoint' => 'topicClusterAudit', 'keyword' => 'required'],
        'competitors' => ['endpoint' => 'competitorAudit', 'keyword' => 'none'],
    ];

    protected ?int $creditCost = 3;

    public function getName(): string
    {
        return 'auditContent';
    }

    public function getDescription(): string
    {
        return 'Analyse what one page does not yet cover, rated answered/partial/open against '
            .'its content. Pick the angle with type: reader questions, weak rankings, topic clusters or a '
            .'competitor gap. Costs 2 credits for questions, 3 otherwise.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => self::pageIdSchemaProperty(),
                'url' => self::urlSchemaProperty(),
                'type' => [
                    'type' => 'string',
                    'enum' => array_keys(self::ANALYSES),
                    'description' => 'questions: real user questions from Google People-also-ask plus AI-derived ones (2 credits). '
                        .'gap: keywords the page ranks for but too weakly for traffic. '
                        .'cluster: the market subtopics around a focus keyword, needs keyword. '
                        .'competitors: top competitors of the domain and the keyword gap against the strongest.',
                ],
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Focus keyword. Required for cluster; adds the live People-also-ask questions to questions; ignored otherwise.',
                ],
            ] + self::commonSchemaProperties(),
            'required' => ['type'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $type = trim((string) ($params['type'] ?? ''));
        if (!isset(self::ANALYSES[$type])) {
            return $this->textError(sprintf(
                'type must be one of: %s.',
                implode(', ', array_keys(self::ANALYSES)),
            ));
        }
        $analysis = self::ANALYSES[$type];

        $url = $this->validatedUrl($params);
        if ($url instanceof CallToolResult) {
            return $url;
        }

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if (mb_strlen($keyword) > 200) {
            return $this->textError('keyword exceeds the maximum length of 200 characters.');
        }
        if ('required' === $analysis['keyword'] && '' === $keyword) {
            return $this->textError(sprintf('keyword is required for type "%s" (max. 200 characters).', $type));
        }
        if ('none' === $analysis['keyword']) {
            $keyword = '';
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
        if ($market instanceof CallToolResult && !('questions' === $type && '' === $keyword)) {
            return $market;
        }

        $body = $this->sendAiRequest(
            $analysis['endpoint'],
            $this->requestData($type, $url, $keyword, $content, \is_string($market) ? $market : null),
            ['text' => $model],
            $this->normalizedLanguage($params),
        );

        return $this->auditResult($type, $url, $keyword, $this->summarize($type, $body, $url, $keyword), $body);
    }

    /**
     * @return array<string, string>
     */
    private function requestData(string $type, string $url, string $keyword, string $content, ?string $market): array
    {
        $data = 'cluster' === $type
            ? ['keyword' => $keyword, 'request_content' => $content]
            : ['url' => $url, 'request_content' => $content];

        if ('questions' === $type && '' !== $keyword) {
            $data['keyword'] = $keyword;
        }
        if (null !== $market) {
            $data['market'] = $market;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarize(string $type, array $body, string $url, string $keyword): string
    {
        return match ($type) {
            'questions' => $this->summarizeQuestions($body, $url),
            'gap' => $this->summarizeGap($body, $url),
            'cluster' => $this->summarizeCluster($body, $keyword),
            default => $this->summarizeCompetitors($body),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarizeQuestions(array $body, string $url): string
    {
        $lines = ['## Question coverage: '.$url];
        $lines[] = self::coverageLine(\is_array($body['summary'] ?? null) ? $body['summary'] : []);
        if (true === ($body['serpUnavailable'] ?? false)) {
            $lines[] = '- SERP questions unavailable — AI-derived questions only.';
        }

        foreach (\is_array($body['questions'] ?? null) ? $body['questions'] : [] as $question) {
            if (!\is_array($question) || 'answered' === ($question['status'] ?? '')) {
                continue;
            }
            $lines[] = sprintf(
                '- [%s/%s] %s — %s',
                (string) ($question['status'] ?? ''),
                (string) ($question['source'] ?? ''),
                (string) ($question['question'] ?? ''),
                (string) ($question['note'] ?? ''),
            );
        }
        $lines[] = 'Open questions are candidates for an FAQ or answer section on the page.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarizeGap(array $body, string $url): string
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

        $lines[] = self::coverageLine(\is_array($body['summary'] ?? null) ? $body['summary'] : []);
        foreach (\is_array($body['gaps'] ?? null) ? $body['gaps'] : [] as $gap) {
            if (!\is_array($gap)) {
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

    /**
     * @param array<string, mixed> $body
     */
    private function summarizeCluster(array $body, string $keyword): string
    {
        $lines = ['## Topic clusters: "'.$keyword.'"'];
        if (true === ($body['noClusters'] ?? false)) {
            $lines[] = 'No cluster data found for this keyword — try a broader focus keyword.';

            return implode("\n", $lines);
        }

        $lines[] = self::coverageLine(\is_array($body['summary'] ?? null) ? $body['summary'] : []);
        foreach (\is_array($body['clusters'] ?? null) ? $body['clusters'] : [] as $cluster) {
            if (!\is_array($cluster)) {
                continue;
            }
            $keywords = array_column(
                \array_slice(\is_array($cluster['keywords'] ?? null) ? $cluster['keywords'] : [], 0, 3),
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

    /**
     * @param array<string, mixed> $body
     */
    private function summarizeCompetitors(array $body): string
    {
        $lines = ['## Competitors: '.(string) ($body['target'] ?? '')];
        foreach (\is_array($body['competitors'] ?? null) ? $body['competitors'] : [] as $competitor) {
            if (!\is_array($competitor)) {
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
        $lines[] = self::coverageLine(\is_array($body['summary'] ?? null) ? $body['summary'] : []);
        foreach (\is_array($body['gaps'] ?? null) ? $body['gaps'] : [] as $gap) {
            if (!\is_array($gap)) {
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
