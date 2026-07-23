<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadChildrenTool extends AbstractTool
{
    private const MAX_CHILDREN_PER_FIELD = 200;
    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly ContentRepository $contentRepository,
        private readonly RecordRepository $recordRepository,
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
                'table' => ['type' => 'string', 'default' => 'tt_content', 'description' => 'Parent table (default tt_content).'],
                'language' => ['type' => 'integer', 'default' => 0, 'description' => 'Language UID for container children (default 0).'],
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
     * @return array<string, list<array{uid: int, label: string, type: string}>>
     */
    private function containerChildren(int $uid, string $cType, int $language): array
    {
        $registry = $this->tcaLabel->getContainerRegistry();
        if (null === $registry || !$registry->isContainerElement($cType)) {
            return [];
        }

        $children = [];
        foreach ($this->contentRepository->findContainerChildren($uid, $language) as $child) {
            $childUid = (int) ($child['uid'] ?? 0);
            $children[] = [
                'uid' => $childUid,
                'label' => (string) ($child['header'] ?? '') ?: '(no header)',
                'type' => sprintf('%s, colPos %d', (string) ($child['CType'] ?? ''), (int) ($child['colPos'] ?? 0)),
            ];
        }

        return [] !== $children ? ['container slots' => $children] : [];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, list<array{uid: int, label: string, type: string}>>
     */
    private function inlineChildren(string $table, array $record, int $uid): array
    {
        $groups = [];

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
                $childUids = $this->recordRepository->findUidsByCriteria(
                    $foreignTable,
                    null,
                    [(string) $config['foreign_field'] => $uid],
                    null,
                    null,
                    'uid',
                    self::MAX_CHILDREN_PER_FIELD,
                    0,
                );

                $children = [];
                $labelField = $this->tcaCompatibilityService->getLabelField($foreignTable);
                foreach ($childUids as $childUid) {
                    $childRecord = BackendUtility::getRecordWSOL($foreignTable, $childUid);
                    $children[] = [
                        'uid' => $childUid,
                        'label' => (string) ($childRecord[$labelField] ?? '') ?: '(no label)',
                        'type' => $foreignTable,
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
     * @param array<string, list<array{uid: int, label: string, type: string}>> $groups
     */
    private function render(string $table, int $uid, array $groups): string
    {
        $text = sprintf("## Children of %s:%d\n\n", $table, $uid);
        foreach ($groups as $groupLabel => $children) {
            $text .= sprintf("**%s** (%d):\n", $groupLabel, count($children));
            foreach ($children as $child) {
                $text .= sprintf("- uid %d — %s [%s]\n", $child['uid'], $child['label'], $child['type']);
            }
            $text .= "\n";
        }

        return rtrim($text);
    }
}
