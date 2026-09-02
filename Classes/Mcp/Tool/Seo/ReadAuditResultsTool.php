<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Seo;

use AutoDudes\AiSuite\Domain\Repository\AuditResultRepository;
use AutoDudes\AiSuite\Utility\AuditScoreUtility;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadAuditResultsTool extends AbstractTool
{
    private const AUDIT_TYPES = ['seo', 'a11y', 'questions', 'gap', 'cluster', 'competitors'];

    private const TYPE_LABELS = [
        'seo' => 'SEO & performance',
        'a11y' => 'Accessibility (WCAG)',
        'questions' => 'Question coverage (GEO/FAQ)',
        'gap' => 'Content gap',
        'cluster' => 'Topic clusters',
        'competitors' => 'Competitors',
    ];

    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly AuditResultRepository $auditResults,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readAuditResults';
    }

    public function getDescription(): string
    {
        return 'Read the audit results already stored for a TYPO3 page (SEO, accessibility, questions, '
            .'content gap, topic clusters, competitors) — free, no new audit is started. '
            .'Pass auditType for the full stored details of one audit. Requires read permission on the page.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => [
                    'type' => 'integer',
                    'description' => 'TYPO3 page UID',
                ],
                'auditType' => [
                    'type' => 'string',
                    'enum' => self::AUDIT_TYPES,
                    'description' => 'Return the full stored result of this audit type instead of the overview.',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'ISO language code. Default: default language.',
                ],
            ],
            'required' => ['pageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $languageUid = $this->recordAccess->resolveLanguageUid($params['language'] ?? null, $pageId);
        $auditType = trim((string) ($params['auditType'] ?? ''));

        $this->recordAccess->assertPagePerm($pageId, Permission::PAGE_SHOW);

        if ('' !== $auditType) {
            return $this->detail($pageId, $auditType, $languageUid);
        }

        $lines = [sprintf('## Stored audits for page %d', $pageId)];
        $structured = ['pageId' => $pageId, 'languageUid' => $languageUid, 'audits' => []];
        foreach (self::AUDIT_TYPES as $type) {
            $stored = $this->auditResults->findLatest($pageId, $type, $languageUid);
            if (null === $stored) {
                continue;
            }
            $lines[] = $this->overviewLine($type, $stored);
            $structured['audits'][$type] = [
                'runDate' => date('Y-m-d H:i', $stored['runTs']),
                'keyword' => $stored['keyword'],
                'summary' => $this->summaryOf($stored),
            ];
        }
        if ([] === $structured['audits']) {
            $lines[] = 'No stored audits for this page and language yet. Run one with auditSeo, '
                .'auditAccessibility, auditQuestions, auditContentGap, auditTopicCluster or auditCompetitors.';
        } else {
            $lines[] = '';
            $lines[] = 'Pass auditType to read the full stored details of one audit (free).';
        }

        return $this->structuredResult(implode("\n", $lines), $structured);
    }

    private function detail(int $pageId, string $auditType, int $languageUid): CallToolResult
    {
        $stored = $this->auditResults->findLatest($pageId, $auditType, $languageUid);
        if (null === $stored) {
            return $this->textError(sprintf(
                'No stored "%s" audit for page %d in this language. Run one with the matching audit* tool.',
                $auditType,
                $pageId,
            ));
        }
        $text = sprintf(
            "## Stored %s audit for page %d (run %s)\n%s",
            self::TYPE_LABELS[$auditType] ?? $auditType,
            $pageId,
            date('Y-m-d H:i', $stored['runTs']),
            $this->overviewLine($auditType, $stored),
        );

        return $this->structuredResult($text, [
            'pageId' => $pageId,
            'auditType' => $auditType,
            'languageUid' => $languageUid,
            'runDate' => date('Y-m-d H:i', $stored['runTs']),
            'keyword' => $stored['keyword'],
            'result' => $stored['result'],
        ]);
    }

    /**
     * @param array{keyword: string, runTs: int, result: array<string, mixed>} $stored
     */
    private function overviewLine(string $type, array $stored): string
    {
        $label = self::TYPE_LABELS[$type] ?? $type;
        $keyword = '' !== $stored['keyword'] ? sprintf(', keyword "%s"', $stored['keyword']) : '';
        $summary = $this->summaryOf($stored);

        if (in_array($type, ['seo', 'a11y'], true)) {
            $score = AuditScoreUtility::fromSummary($summary);

            return sprintf(
                '- [%s] %s: score %d (%s) — %d errors, %d warnings, %d notices%s (run %s)',
                $type,
                $label,
                $score,
                AuditScoreUtility::range($score),
                (int) ($summary['errors'] ?? 0),
                (int) ($summary['warnings'] ?? 0),
                (int) ($summary['notices'] ?? 0),
                $keyword,
                date('Y-m-d H:i', $stored['runTs']),
            );
        }

        $honesty = '';
        if (true === ($stored['result']['domainUnknown'] ?? false)) {
            $honesty = ' — NOTE: no ranking data for this domain, no positive result';
        } elseif (true === ($stored['result']['pageNotRanking'] ?? false)) {
            $honesty = ' — NOTE: the page itself has no rankings yet';
        }

        return sprintf(
            '- [%s] %s: %d open, %d partial, %d answered (of %d)%s%s (run %s)',
            $type,
            $label,
            (int) ($summary['open'] ?? 0),
            (int) ($summary['partial'] ?? 0),
            (int) ($summary['answered'] ?? 0),
            (int) ($summary['total'] ?? 0),
            $keyword,
            $honesty,
            date('Y-m-d H:i', $stored['runTs']),
        );
    }

    /**
     * @param array{result: array<string, mixed>} $stored
     *
     * @return array<string, mixed>
     */
    private function summaryOf(array $stored): array
    {
        // seo/a11y nest the counts under audit.summary, the analysis types store a flat one
        $summary = $stored['result']['audit']['summary'] ?? $stored['result']['summary'] ?? [];

        return is_array($summary) ? $summary : [];
    }
}
