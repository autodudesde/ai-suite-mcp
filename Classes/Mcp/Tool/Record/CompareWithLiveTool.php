<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceComparisonService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class CompareWithLiveTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly WorkspaceComparisonService $comparison,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'compareWithLive';
    }

    public function getDescription(): string
    {
        return 'Compare the workspace draft of a record (or a whole page/filter set) against the LIVE state — a field-level diff showing what this workspace changes. '
            .'Provide uid for a single record (pass the live uid you see in the page module), or pid/filters to diff a set. '
            .'For a set it reports CHANGED (differing fields), ADDED (new in the workspace, no live version) and REMOVED (deleted in the workspace) records; unchanged records are summarized as a count. '
            .'Only usable when the session is bound to a non-live workspace. ADDED/REMOVED are always listed in full; CHANGED is capped by limit.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'TCA table name'],
                'uid' => ['type' => 'integer', 'description' => 'Single record (the live UID as shown in the backend)'],
                'pid' => ['type' => 'integer', 'description' => 'Page UID — diff all changed records on this page'],
                'filters' => [
                    'type' => 'object',
                    'description' => 'Field=value filters (exact match; "" matches empty) to scope the set, same shape as readRecords.',
                ],
                'limit' => ['type' => 'integer', 'default' => 50, 'description' => 'Max CHANGED records to render. Default: 50, max: 200.'],
            ],
            'required' => ['table'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $uid = isset($params['uid']) ? (int) $params['uid'] : null;
        $pid = isset($params['pid']) ? (int) $params['pid'] : null;
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $limit = min((int) ($params['limit'] ?? 50), 200);

        if (null === $uid && null === $pid && empty($filters)) {
            return $this->textError('Provide uid, pid, or filters.');
        }

        $this->recordAccess->validateTableReadAccess($table);

        if (!$this->comparison->isWorkspacesLoaded()) {
            return $this->textResult('The workspaces extension is not installed — there is no draft/live distinction, so live and workspace state are identical. Nothing to compare.');
        }
        $currentWs = $this->comparison->getCurrentWorkspaceId();
        if (0 === $currentWs) {
            return $this->textResult('This MCP session is on the LIVE workspace, so there is nothing to compare. Bind the token to a non-live workspace (or set mcpWriteMode=workspace) to use compareWithLive.');
        }

        try {
            if (!$this->tcaCompatibilityService->isWorkspaceAware($table)) {
                return $this->textResult(sprintf('Table "%s" is not workspace-aware; its live and workspace versions are always identical.', $this->tcaLabel->getTableLabel($table)));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('CompareWithLiveTool: workspace-aware check failed, proceeding', ['table' => $table, 'error' => $e->getMessage()]);
        }

        if (null !== $uid) {
            $this->recordAccess->assertRecordReadAccess($table, $uid);
            $diff = $this->comparison->compareSingle($table, $uid, $currentWs);

            return $this->textResult($this->renderSingle($table, $diff, $currentWs));
        }

        $allowedPids = null;
        if (null !== $pid) {
            $this->recordAccess->assertPagePerm($pid, Permission::PAGE_SHOW);
        } else {
            $beUser = $this->getBackendUser();
            if (null !== $beUser && !$beUser->isAdmin()) {
                $allowedPids = $this->recordAccess->getReadablePageIds(0, 99);
                if (empty($allowedPids)) {
                    return $this->textResult(sprintf('No %s records accessible in your webmounts.', $table));
                }
            }
        }

        $sanitizedFilters = [];
        foreach ($filters as $field => $value) {
            if (is_string($field) && $this->recordAccess->canAccessField($table, $field)) {
                $sanitizedFilters[$field] = is_scalar($value) ? $value : null;
            }
        }

        $set = $this->comparison->compareSet($table, $pid, $sanitizedFilters, $allowedPids, $currentWs, $limit);

        return $this->textResult($this->renderSet($table, $set, $currentWs, $pid));
    }

    /**
     * @param array{status: string, liveUid: int, label: string, changes: array<string, array{old: string, new: string}>, newFields: array<string, string>} $diff
     */
    private function renderSingle(string $table, array $diff, int $currentWs): string
    {
        $label = '' !== $diff['label'] ? ' — '.$diff['label'] : '';
        $lines = [sprintf('## Diff %s:%d (Workspace %d vs Live)%s', $table, $diff['liveUid'], $currentWs, $label), ''];

        switch ($diff['status']) {
            case 'added':
                $lines[] = '_New in workspace — no live version yet._';
                $lines[] = '';
                foreach ($diff['newFields'] as $field => $value) {
                    $lines[] = $this->renderAddedLine($table, $field, $value);
                }

                break;

            case 'removed':
                $lines[] = '_Present in live, deleted in workspace._';

                break;

            case 'changed':
                foreach ($diff['changes'] as $field => $change) {
                    $lines[] = $this->renderChangeLine($table, $field, $change, null);
                }

                break;

            default:
                $lines[] = '_No differences between workspace and live._';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{changed: list<array{liveUid: int, label: string, changes: array<string, array{old: string, new: string}>}>, added: list<array{uid: int, label: string, fields: array<string, string>}>, removed: list<array{liveUid: int, label: string}>, unchangedCount: null|int, truncated: bool} $set
     */
    private function renderSet(string $table, array $set, int $currentWs, ?int $pid): string
    {
        $scope = null !== $pid ? sprintf('on page %d', $pid) : 'matching filters';
        $lines = [sprintf('## Compare with Live — `%s` %s (Workspace %d vs Live)', $table, $scope, $currentWs), ''];

        $total = count($set['changed']) + count($set['added']) + count($set['removed']);
        if (0 === $total) {
            $lines[] = '_No workspace changes in this scope._';
        }

        foreach ($set['changed'] as $rec) {
            $lines[] = sprintf('### CHANGED: %s:%d — %s', $table, $rec['liveUid'], $rec['label']);
            foreach ($rec['changes'] as $field => $change) {
                $lines[] = $this->renderChangeLine($table, $field, $change, 300);
            }
            $lines[] = '';
        }
        foreach ($set['added'] as $rec) {
            $lines[] = sprintf('### ADDED in workspace: %s:%d — %s', $table, $rec['uid'], $rec['label']);
            foreach ($rec['fields'] as $field => $value) {
                $lines[] = $this->renderAddedLine($table, $field, $value);
            }
            $lines[] = '';
        }
        foreach ($set['removed'] as $rec) {
            $lines[] = sprintf('### REMOVED in workspace: %s:%d — %s', $table, $rec['liveUid'], $rec['label']);
            $lines[] = '(present in live, deleted in workspace)';
            $lines[] = '';
        }

        if ($set['truncated']) {
            $lines[] = sprintf('⚠️ More changed records exist than shown — increase `limit` (current: %d) or scope by filters.', count($set['changed']));
        }
        if (null !== $set['unchangedCount']) {
            $lines[] = sprintf('%d record(s) unchanged.', $set['unchangedCount']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{old: string, new: string} $change
     */
    private function renderChangeLine(string $table, string $field, array $change, ?int $maxLength): string
    {
        return sprintf(
            "`%s` (%s): '%s' -> '%s'",
            $field,
            $this->tcaLabel->getFieldLabel($table, $field),
            $this->outputFormatter->displayValue($change['old'], $maxLength),
            $this->outputFormatter->displayValue($change['new'], $maxLength),
        );
    }

    private function renderAddedLine(string $table, string $field, string $value): string
    {
        return sprintf(
            '`%s` (%s): (new) -> %s',
            $field,
            $this->tcaLabel->getFieldLabel($table, $field),
            $this->outputFormatter->displayValue($value, 300),
        );
    }
}
