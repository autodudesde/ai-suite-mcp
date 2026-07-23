<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\BackendLayoutColumnService;
use AutoDudes\AiSuiteMcp\Mcp\Service\FieldCurationService;
use AutoDudes\AiSuiteMcp\Mcp\Service\FieldFormatHintService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Absorbed the former `listColumnPositions` tool.
 *
 * Both rendered the page's colPos block from the same BackendLayoutColumnService; the only thing
 * listColumnPositions added was the list of container *instances* already sitting on the page. That
 * is a second section of one answer ("where can content go on this page?"), not a second question,
 * so it moved behind the `includeContainers` flag and the tool went away.
 *
 * Note the two kinds of container information are different: the CType listing below reports the
 * slot *definitions* of each container type (schema), while includeContainers reports the *instances*
 * with their UIDs (data). A model needs the UID to set tx_container_parent.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class ListContentTypesTool extends AbstractDataTool
{
    /**
     * The listing is the one place where the model picks a CType, so what is missing here it cannot
     * recover later. A Content Block accordion used to render as "[relations: zm_col_accordion]" --
     * no description, no child table, no child fields -- and a model asked for a FAQ element could
     * not tell it apart from noise; measured, it fell back to a plain text element. The caps below
     * keep that fix from inflating the ~40 core CTypes, none of which has an inline collection.
     */
    private const MAX_INLINE_FIELDS_PER_CTYPE = 3;

    private const MAX_CHILD_FIELDS = 8;

    private const MAX_DESCRIPTION_LENGTH = 100;

    protected ?string $requiredScope = null;
    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BackendLayoutColumnService $backendLayoutColumns,
        private readonly ContentRepository $contentRepository,
        private readonly FieldFormatHintService $fieldFormatHints,
        private readonly FieldCurationService $fieldCuration,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'listContentTypes';
    }

    public function getDescription(): string
    {
        return 'The content element types (CTypes) available on a page, the valid top-level column positions '
            .'(colPos) of its layout, and whether each type has text, image or child-record fields. Pass '
            .'includeContainers to also list the container elements already on the page, with the UID and slot '
            .'a child would go into.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'Page UID'],
                'includeContainers' => ['type' => 'boolean', 'default' => false, 'description' => 'Also list the container elements that already exist on the page, each with its UID and inner slots. Needed to drop a child into an existing container via `tx_container_parent` + `colPos`.'],
                'languageUid' => ['type' => 'integer', 'default' => 0, 'description' => 'Language of the container scan (default 0 = default language). Only used with includeContainers.'],
            ],
            'required' => ['pageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $includeContainers = (bool) ($params['includeContainers'] ?? false);
        $languageUid = (int) ($params['languageUid'] ?? 0);
        $this->recordAccess->assertPagePerm($pageId, Permission::PAGE_SHOW);

        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if (null === $page) {
            return $this->textError("Page {$pageId} not found.");
        }

        $items = $this->tcaCompatibilityService->getFieldConfiguration('tt_content', 'CType')['items'] ?? [];
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $removed = array_map('trim', explode(',', (string) ($tsConfig['TCEFORM.']['tt_content.']['CType.']['removeItems'] ?? '')));

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['value']) || '--div--' === $item['value'] || '' === $item['value']) {
                continue;
            }
            if (in_array($item['value'], $removed, true)) {
                continue;
            }

            $ctype = $item['value'];
            $fieldCategories = $this->categorizeFieldsForCType($ctype);

            $entry = [
                'ctype' => $ctype,
                'label' => $this->tcaLabel->resolveLabel($item['label'] ?? $ctype),
                'description' => $this->resolveDescription($item['description'] ?? null),
                'textFields' => $fieldCategories['text'],
                'richTextFields' => $fieldCategories['richtext'],
                'fileFields' => $fieldCategories['file'],
                'relationFields' => $fieldCategories['relation'],
                'flexFields' => $fieldCategories['flex'],
                'inlineChildren' => $this->describeInlineChildren((string) $ctype),
                'isContainer' => false,
                'containerColumns' => [],
                'formatHints' => $this->fieldFormatHints->forType('tt_content', (string) $ctype),
            ];

            $registry = $this->tcaLabel->getContainerRegistry();
            if (null !== $registry && $registry->isContainerElement($ctype)) {
                $entry['isContainer'] = true;
                foreach ($registry->getAvailableColumns($ctype) as $column) {
                    $entry['containerColumns'][] = [
                        'colPos' => (int) ($column['colPos'] ?? 0),
                        'name' => $this->tcaLabel->resolveLabel((string) ($column['name'] ?? '')),
                    ];
                }
            }

            $result[] = $entry;
        }

        $text = sprintf("Content types on page %d:\n\n", $pageId);

        $columns = $this->backendLayoutColumns->getPageColumns($pageId);
        $text .= "Valid column positions (colPos) for placing a standard element on this page:\n";
        foreach ($columns as $colPos => $label) {
            $text .= sprintf("- %s → colPos: %d\n", $label, $colPos);
        }
        $text .= "\n";

        foreach ($result as $t) {
            $flags = [];
            if (!empty($t['textFields'])) {
                $flags[] = 'text: '.implode(', ', $t['textFields']);
            }
            if (!empty($t['richTextFields'])) {
                $flags[] = 'richtext: '.implode(', ', $t['richTextFields']);
            }
            if (!empty($t['fileFields'])) {
                $flags[] = 'files: '.implode(', ', $t['fileFields']);
            }
            if (!empty($t['relationFields'])) {
                $flags[] = 'relations: '.implode(', ', $t['relationFields']);
            }
            if (!empty($t['inlineChildren'])) {
                $flags[] = 'children: '.implode(', ', array_column($t['inlineChildren'], 'field'));
            }
            if (!empty($t['flexFields'])) {
                $flags[] = 'config: '.implode(', ', $t['flexFields']);
            }
            $flagStr = !empty($flags) ? ' ['.implode(' | ', $flags).']' : '';
            $containerMark = !empty($t['isContainer']) ? ' [container]' : '';
            $text .= sprintf("- **%s** (`%s`)%s%s\n", $t['label'], $t['ctype'], $containerMark, $flagStr);

            if ('' !== $t['description']) {
                $text .= sprintf("    %s\n", $t['description']);
            }

            foreach ($t['inlineChildren'] as $child) {
                $text .= sprintf(
                    "    - `%s`: child records in `%s`, fields: %s.\n",
                    $child['field'],
                    $child['childTable'],
                    implode(', ', $child['childFields']),
                );
                $text .= sprintf("      Children are nested objects inside `%s`.\n", $child['field']);
            }

            foreach ($t['flexFields'] as $flexField) {
                $text .= sprintf(
                    "    - `%s` holds this element's settings (how it renders — a column count, for instance). Call readFlexFormSchema(field: \"%s\", type: \"%s\") before writing it.\n",
                    $flexField,
                    $flexField,
                    $t['ctype'],
                );
            }

            foreach ($t['formatHints'] as $hintField => $hint) {
                $text .= sprintf("    - Format of `%s`: %s\n", $hintField, $hint);
            }

            if (!empty($t['containerColumns'])) {
                $text .= "    Children go into one of these slots (set `tx_container_parent` to the container UID and `colPos` accordingly):\n";
                foreach ($t['containerColumns'] as $col) {
                    $text .= sprintf("    - %s → colPos: %d\n", $col['name'], $col['colPos']);
                }
            }
        }

        if ($includeContainers) {
            $text .= $this->renderContainerInstances($pageId, $languageUid);
        }

        return $this->textResult($text);
    }

    /**
     * The container elements that exist on the page right now, with the UID a child must reference.
     * Came over from listColumnPositions when that tool was absorbed.
     */
    private function renderContainerInstances(int $pageId, int $languageUid): string
    {
        // Every branch answers. The old listColumnPositions returned an empty string when containers
        // were unavailable — fine for a whole tool, wrong for a flag: silence is indistinguishable
        // from "includeContainers was ignored", and the model cannot tell whether to retry, give up,
        // or place the element at top level.
        $registry = $this->tcaLabel->getContainerRegistry();
        if (null === $registry) {
            return "\nContainer elements are not available here (EXT:container is not installed).\n";
        }

        $cTypes = $registry->getRegisteredCTypes();
        if ([] === $cTypes) {
            return "\nNo container content types are registered on this installation.\n";
        }

        $containers = $this->contentRepository->findContainersOnPage($pageId, $languageUid, $cTypes);
        if ([] === $containers) {
            return "\nNo container elements exist on this page yet.\n";
        }

        $text = "\nExisting containers on this page (drop children into them via `tx_container_parent` + `colPos`):\n";
        foreach ($containers as $container) {
            $cType = (string) $container['CType'];
            $headerLabel = '' !== (string) ($container['header'] ?? '')
                ? (string) $container['header']
                : sprintf('Container UID %d', (int) $container['uid']);
            $text .= sprintf("\n- **%s** (%s, UID: %d)\n", $headerLabel, $this->tcaLabel->resolveCTypeLabel($cType), (int) $container['uid']);
            foreach ($registry->getAvailableColumns($cType) as $col) {
                $text .= sprintf(
                    "    - %s → tx_container_parent: %d, colPos: %d\n",
                    $this->tcaLabel->resolveLabel((string) ($col['name'] ?? '')),
                    (int) $container['uid'],
                    (int) ($col['colPos'] ?? 0),
                );
            }
        }

        return $text;
    }

    /**
     * The item description a Content Block declares in its config.yaml. This is what maps an intent
     * ("a FAQ element") onto a CType whose name gives nothing away (`zm_accordion`).
     */
    private function resolveDescription(mixed $description): string
    {
        if (!is_string($description) || '' === $description) {
            return '';
        }

        $resolved = trim($this->tcaLabel->resolveLabel($description));
        if (mb_strlen($resolved) <= self::MAX_DESCRIPTION_LENGTH) {
            return $resolved;
        }

        return mb_substr($resolved, 0, self::MAX_DESCRIPTION_LENGTH).'...';
    }

    /**
     * The inline collections of a CType, with the table their children live in and the fields those
     * children take. writeRecords already expands nested children (NestedChildExpanderService), but
     * that fact only lived in its own schema description -- behind a tool the model has not opened
     * at the moment it chooses a type.
     *
     * FAL fields are skipped: they are `type: file`, already reported as fileFields, and their
     * payload shape (uid_local) is a different mechanism described in the writeRecords schema.
     *
     * @return list<array{field: string, childTable: string, childFields: list<string>}>
     */
    private function describeInlineChildren(string $cType): array
    {
        $children = [];

        try {
            if (!$this->tcaCompatibilityService->hasSubSchema('tt_content', $cType)) {
                return [];
            }

            foreach ($this->tcaCompatibilityService->getFieldNamesForType('tt_content', $cType) as $fieldName) {
                if (count($children) >= self::MAX_INLINE_FIELDS_PER_CTYPE) {
                    break;
                }

                $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration('tt_content', $cType, $fieldName);
                if ('inline' !== ($config['type'] ?? '')) {
                    continue;
                }

                $childTable = (string) ($config['foreign_table'] ?? '');
                if ('' === $childTable || 'sys_file_reference' === $childTable) {
                    continue;
                }

                $childFields = $this->curateChildFields($childTable, $config);
                if ([] === $childFields) {
                    continue;
                }

                $children[] = [
                    'field' => $fieldName,
                    'childTable' => $childTable,
                    'childFields' => $childFields,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ListContentTypesTool: inline child introspection failed, omitting children', [
                'cType' => $cType,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $parentConfig
     *
     * @return list<string> rendered as "name (type[, required])"
     */
    private function curateChildFields(string $childTable, array $parentConfig): array
    {
        // The parent pointer and the match fields are set by the expander from the TCA, so a model
        // that reads them here would only be tempted to fill them in itself.
        $skip = array_filter([
            (string) ($parentConfig['foreign_field'] ?? ''),
            (string) ($parentConfig['foreign_table_field'] ?? ''),
        ]);
        foreach (array_keys((array) ($parentConfig['foreign_match_fields'] ?? [])) as $matchField) {
            $skip[] = (string) $matchField;
        }

        $fields = [];
        $omitted = 0;

        foreach ($this->tcaCompatibilityService->getFieldNamesForType($childTable, null) as $fieldName) {
            if ($this->fieldCuration->isHousekeeping($fieldName) || in_array($fieldName, $skip, true)) {
                continue;
            }

            $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($childTable, null, $fieldName);
            $type = (string) ($config['type'] ?? '');
            if (in_array($type, ['passthrough', 'inline', 'file'], true)) {
                continue;
            }

            if (count($fields) >= self::MAX_CHILD_FIELDS) {
                ++$omitted;

                continue;
            }

            $descriptor = $this->tcaCompatibilityService->isRichTextFieldConfig($config) ? 'richtext' : $type;
            if ($this->tcaCompatibilityService->isFieldRequired($config)) {
                $descriptor .= ', required';
            }
            $fields[] = sprintf('%s (%s)', $fieldName, $descriptor);
        }

        if ($omitted > 0) {
            $fields[] = sprintf('+%d more (readRecordSchema)', $omitted);
        }

        return $fields;
    }

    /**
     * @return array{text: list<string>, richtext: list<string>, file: list<string>, relation: list<string>, flex: list<string>}
     */
    private function categorizeFieldsForCType(string $cType): array
    {
        $categories = ['text' => [], 'richtext' => [], 'file' => [], 'relation' => [], 'flex' => []];

        try {
            if (!$this->tcaCompatibilityService->hasSubSchema('tt_content', $cType)) {
                return $categories;
            }

            foreach ($this->tcaCompatibilityService->getFieldNamesForType('tt_content', $cType) as $fieldName) {
                $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration('tt_content', $cType, $fieldName);
                $type = (string) ($config['type'] ?? '');

                if ($this->tcaCompatibilityService->isRichTextFieldConfig($config)) {
                    $categories['richtext'][] = $fieldName;
                } elseif ('flex' === $type) {
                    // Fell into no bucket at all, so a configurable CType looked exactly like a
                    // plain one and nothing ever suggested calling readFlexFormSchema. A card group
                    // keeps its column count in here.
                    $categories['flex'][] = $fieldName;
                } elseif (\in_array($type, ['input', 'text', 'email', 'link'], true)) {
                    $categories['text'][] = $fieldName;
                } elseif ('file' === $type) {
                    $categories['file'][] = $fieldName;
                } elseif ($this->tcaCompatibilityService->isRelationalFieldConfig($config)) {
                    $categories['relation'][] = $fieldName;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ListContentTypesTool: TCA introspection for tt_content sub-schema failed, returning partial categories', [
                'cType' => $cType,
                'error' => $e->getMessage(),
            ]);
        }

        return $categories;
    }
}
