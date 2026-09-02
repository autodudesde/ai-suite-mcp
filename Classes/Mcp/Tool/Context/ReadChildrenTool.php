<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceRecordService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadChildrenTool extends AbstractTool
{
    private const MAX_CHILDREN_PER_FIELD = 200;
    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly ContentRepository $contentRepository,
        private readonly RecordRepository $recordRepository,
        private readonly WorkspaceRecordService $workspaceRecords,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readChildren';
    }

    public function getDescription(): string
    {
        return 'List the child records of a record — container children and IRRE/inline children (e.g. the items '
            .'of a card group or accordion). Returns the actual child records (uid, type, label) grouped by relation, '
            .'so you can then edit them (e.g. with bulkReplaceText or writeRecords). Requires read access.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uid' => ['type' => 'integer', 'description' => 'UID of the parent record.'],
                'table' => ['type' => 'string', 'default' => 'tt_content', 'description' => 'Parent table.'],
                'language' => ['type' => 'integer', 'default' => 0, 'description' => 'Language UID for container children; 0 is the default language.'],
            ],
            'required' => ['uid'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $uid = (int) $params['uid'];
        $table = (string) ($params['table'] ?? 'tt_content');
        $language = (int) ($params['language'] ?? 0);

        $record = $this->recordAccess->assertRecordReadAccess($table, $uid);

        $groups = [];

        if ('tt_content' === $table) {
            $groups = $this->containerChildren($uid, (string) ($record['CType'] ?? ''), $language);
        }
        foreach ($this->inlineChildren($table, $record, $uid) as $label => $children) {
            $groups[$label] = $children;
        }

        if ([] === $groups) {
            return $this->textResult(sprintf('%s:%d has no child records.', $table, $uid));
        }

        return $this->textResult($this->render($table, $uid, $groups));
    }

    /**
     * @return array<string, list<array{uid: int, label: string, type: string, state: string}>>
     */
    private function containerChildren(int $uid, string $cType, int $language): array
    {
        $registry = $this->tcaLabel->getContainerRegistry();
        if (null === $registry || !$registry->isContainerElement($cType)) {
            return [];
        }

        $childUids = [];
        foreach ($this->contentRepository->findContainerChildren($uid, $language) as $child) {
            $childUids[] = (int) ($child['uid'] ?? 0);
        }

        $children = [];
        foreach ($this->workspaceRecords->overlay('tt_content', $childUids) as $child) {
            $children[] = [
                'uid' => (int) ($child['uid'] ?? 0),
                'label' => (string) ($child['header'] ?? '') ?: '(no header)',
                'type' => sprintf('%s, colPos %d', (string) ($child['CType'] ?? ''), (int) ($child['colPos'] ?? 0)),
                'state' => $this->workspaceRecords->stateLabel($child),
            ];
        }

        return [] !== $children ? ['container slots' => $children] : [];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, list<array{uid: int, label: string, type: string, state: string}>>
     */
    private function inlineChildren(string $table, array $record, int $uid): array
    {
        $groups = [];
        $targets = $this->workspaceRecords->relationTargets($table, $uid);

        try {
            $typeKey = $this->tcaCompatibilityService->resolveSubSchemaType($table, $record);
            foreach ($this->tcaCompatibilityService->getFieldNamesForType($table, $typeKey) as $fieldName) {
                $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($table, $typeKey, $fieldName);
                if ('inline' !== ($config['type'] ?? '')
                    || empty($config['foreign_table'])
                    || empty($config['foreign_field'])
                    || 'sys_file_reference' === $config['foreign_table']
                ) {
                    continue;
                }

                $foreignTable = (string) $config['foreign_table'];
                $foreignField = (string) $config['foreign_field'];
                $childUids = $this->recordRepository->findUidsByRelation(
                    $foreignTable,
                    $foreignField,
                    $targets,
                    self::MAX_CHILDREN_PER_FIELD,
                );

                $children = [];
                $labelField = $this->tcaCompatibilityService->getLabelField($foreignTable);
                foreach ($this->workspaceRecords->overlay($foreignTable, $childUids) as $childRecord) {
                    // A live row still names the live parent; only the overlaid value decides membership.
                    if (!in_array((int) ($childRecord[$foreignField] ?? 0), $targets, true)) {
                        continue;
                    }

                    $children[] = [
                        'uid' => (int) ($childRecord['uid'] ?? 0),
                        'label' => (string) ($childRecord[$labelField] ?? '') ?: '(no label)',
                        'type' => $foreignTable,
                        'state' => $this->workspaceRecords->stateLabel($childRecord),
                    ];
                }

                if ([] !== $children) {
                    $groups[sprintf('%s (%s)', $this->tcaLabel->getFieldLabel($table, $fieldName), $foreignTable)] = $children;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ReadChildrenTool: inline-child introspection failed', [
                'table' => $table,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
        }

        return $groups;
    }

    /**
     * @param array<string, list<array{uid: int, label: string, type: string, state: string}>> $groups
     */
    private function render(string $table, int $uid, array $groups): string
    {
        $text = sprintf("## Children of %s:%d\n\n", $table, $uid);
        foreach ($groups as $groupLabel => $children) {
            $text .= sprintf("**%s** (%d):\n", $groupLabel, count($children));
            foreach ($children as $child) {
                $marker = '' !== $child['state'] ? ' · '.$child['state'] : '';
                $text .= sprintf("- uid %d — %s [%s%s]\n", $child['uid'], $child['label'], $child['type'], $marker);
            }
            $text .= "\n";
        }

        return rtrim($text);
    }
}
