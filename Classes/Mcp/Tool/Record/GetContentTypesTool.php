<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetContentTypesTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;

    public function getName(): string
    {
        return 'getContentTypes';
    }

    public function getDescription(): string
    {
        return 'List available content element types (CTypes) for a page. '
            .'Shows whether each type has text, image, or child-record fields.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'Page UID'],
            ],
            'required' => ['pageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $this->assertPagePerm($pageId, Permission::PAGE_SHOW);

        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if (null === $page) {
            return new CallToolResult([new TextContent("Page {$pageId} not found.")], isError: true);
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
                'label' => $this->resolveLabel($item['label'] ?? $ctype),
                'textFields' => $fieldCategories['text'],
                'richTextFields' => $fieldCategories['richtext'],
                'fileFields' => $fieldCategories['file'],
                'relationFields' => $fieldCategories['relation'],
                'isContainer' => false,
                'containerColumns' => [],
            ];

            $registry = $this->getContainerRegistry();
            if (null !== $registry && $registry->isContainerElement($ctype)) {
                $entry['isContainer'] = true;
                foreach ($registry->getAvailableColumns($ctype) as $column) {
                    $entry['containerColumns'][] = [
                        'colPos' => (int) ($column['colPos'] ?? 0),
                        'name' => $this->resolveLabel((string) ($column['name'] ?? '')),
                    ];
                }
            }

            $result[] = $entry;
        }

        $text = sprintf("Content types on page %d:\n\n", $pageId);
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
            $flagStr = !empty($flags) ? ' ['.implode(' | ', $flags).']' : '';
            $containerMark = !empty($t['isContainer']) ? ' [container]' : '';
            $text .= sprintf("- **%s** (`%s`)%s%s\n", $t['label'], $t['ctype'], $containerMark, $flagStr);

            if (!empty($t['containerColumns'])) {
                $text .= "    Children go into one of these slots (set `tx_container_parent` to the container UID and `colPos` accordingly):\n";
                foreach ($t['containerColumns'] as $col) {
                    $text .= sprintf("    - %s → colPos: %d\n", $col['name'], $col['colPos']);
                }
            }
        }

        return new CallToolResult([new TextContent($text)]);
    }

    /**
     * Categorize fields for a CType using TcaCompatibilityService.
     * `getEffectiveFieldConfiguration` applies columnsOverrides so e.g. `enableRichtext`
     * set only on the 'text' CType's bodytext override is respected.
     *
     * @return array{text: list<string>, richtext: list<string>, file: list<string>, relation: list<string>}
     */
    private function categorizeFieldsForCType(string $cType): array
    {
        $categories = ['text' => [], 'richtext' => [], 'file' => [], 'relation' => []];

        try {
            if (!$this->tcaCompatibilityService->hasSubSchema('tt_content', $cType)) {
                return $categories;
            }

            foreach ($this->tcaCompatibilityService->getFieldNamesForType('tt_content', $cType) as $fieldName) {
                $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration('tt_content', $cType, $fieldName);
                $type = (string) ($config['type'] ?? '');

                if ($this->tcaCompatibilityService->isRichTextFieldConfig($config)) {
                    $categories['richtext'][] = $fieldName;
                } elseif (\in_array($type, ['input', 'text', 'email', 'link'], true)) {
                    $categories['text'][] = $fieldName;
                } elseif ('file' === $type) {
                    $categories['file'][] = $fieldName;
                } elseif ($this->tcaCompatibilityService->isRelationalFieldConfig($config)) {
                    $categories['relation'][] = $fieldName;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('GetContentTypesTool: TCA introspection for tt_content sub-schema failed, returning partial categories', [
                'cType' => $cType,
                'error' => $e->getMessage(),
            ]);
        }

        return $categories;
    }
}
