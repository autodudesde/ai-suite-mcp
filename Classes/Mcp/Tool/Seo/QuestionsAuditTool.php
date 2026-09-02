<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Seo;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class QuestionsAuditTool extends AbstractAuditAnalysisTool
{
    protected ?int $creditCost = 2;

    public function getName(): string
    {
        return 'auditQuestions';
    }

    public function getDescription(): string
    {
        return 'Analyse the question coverage of one public page URL (GEO/FAQ): real user questions '
            .'from Google\'s People-also-ask (with a focus keyword) plus AI-derived questions, each '
            .'rated answered/partial/open against the page content. Costs 2 credits.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => self::urlSchemaProperty(),
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Optional focus keyword — adds the live People-also-ask questions from the SERP.',
                ],
            ] + self::commonSchemaProperties(),
            'required' => ['url'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $url = $this->validatedUrl($params);
        if ($url instanceof CallToolResult) {
            return $url;
        }
        $keyword = trim((string) ($params['keyword'] ?? ''));
        if (mb_strlen($keyword) > 200) {
            return $this->textError('keyword exceeds the maximum length of 200 characters.');
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
        if ($market instanceof CallToolResult && '' !== $keyword) {
            return $market;
        }

        $data = ['url' => $url, 'request_content' => $content];
        if ('' !== $keyword) {
            $data['keyword'] = $keyword;
        }
        if (\is_string($market)) {
            $data['market'] = $market;
        }
        $body = $this->sendAiRequest('/questionsAudit', $data, ['text' => $model], $this->normalizedLanguage($params));

        return $this->structuredResult($this->summarize($body, $url), $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarize(array $body, string $url): string
    {
        $lines = ['## Question coverage: '.$url];
        $lines[] = self::coverageLine(is_array($body['summary'] ?? null) ? $body['summary'] : []);
        if (true === ($body['serpUnavailable'] ?? false)) {
            $lines[] = '- SERP questions unavailable — AI-derived questions only.';
        }

        foreach (is_array($body['questions'] ?? null) ? $body['questions'] : [] as $question) {
            if (!is_array($question) || 'answered' === ($question['status'] ?? '')) {
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
}
