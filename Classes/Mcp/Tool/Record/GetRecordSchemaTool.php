<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetRecordSchemaTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly RecordRepository $recordRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'getRecordSchema';
    }

    public function getDescription(): string
    {
        return 'Get field schema for a table, optionally filtered by record type '
            .'(e.g. CType for tt_content). Shows labels, types, validation, select options, IRRE children, '
            .'richtext/CKEditor preset, appearance/config defaults, and the tab/palette layout. '
            .'With suggestValues (default true) it also reports the most common existing value of '
            .'configuration select fields (appearance, frame, layout) so you can match the site conventions. '
            .'Respects user permissions.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'Table name (e.g. tt_content, pages)'],
                'type' => ['type' => 'string', 'description' => 'Record type filter (e.g. "textmedia" for tt_content CType)'],
                'suggestValues' => ['type' => 'boolean', 'default' => true, 'description' => 'Suggest the most common existing value for configuration select fields (sampled from existing records).'],
            ],
            'required' => ['table'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $type = (string) ($params['type'] ?? '');
        $suggestValues = (bool) ($params['suggestValues'] ?? true);
        $this->recordAccess->validateTableReadAccess($table);

        $typeKey = ('' !== $type && $this->tcaCompatibilityService->hasSubSchema($table, $type)) ? $type : null;
        $typeField = $this->tcaCompatibilityService->getSubSchemaDivisorFieldName($table);
        $fieldNames = $this->tcaCompatibilityService->getFieldNamesForType($table, $typeKey);

        $fields = [];
        foreach ($fieldNames as $fieldName) {
            $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($table, $typeKey, $fieldName);
            $fieldType = (string) ($config['type'] ?? '');

            if ('passthrough' === $fieldType) {
                continue;
            }
            if (!$this->recordAccess->canAccessField($table, $fieldName)) {
                continue;
            }

            $info = [
                'name' => $fieldName,
                'label' => $this->tcaLabel->getFieldLabel($table, $fieldName),
                'type' => $fieldType,
            ];

            if (isset($config['renderType'])) {
                $info['renderType'] = $config['renderType'];
            }
            if ($this->tcaCompatibilityService->isFieldRequired($config)) {
                $info['required'] = true;
            }
            if (isset($config['max'])) {
                $info['maxLength'] = (int) $config['max'];
            }
            if ($this->tcaCompatibilityService->isRichTextFieldConfig($config)) {
                $info['isRichText'] = true;
                $info['richtextConfiguration'] = (string) ($config['richtextConfiguration'] ?? 'default');
            }
            if (isset($config['default']) && '' !== (string) $config['default']) {
                $info['default'] = (string) $config['default'];
            }
            if (!empty($config['appearance']) && is_array($config['appearance'])) {
                $info['appearance'] = $this->summarizeAppearance($config['appearance']);
            }
            if (\in_array($fieldType, ['select', 'radio', 'check'], true) && isset($config['items'])) {
                $info['options'] = $this->tcaLabel->buildSelectOptions($config['items']);

                if ($suggestValues && $fieldName !== $typeField) {
                    $suggested = $this->suggestCommonValue($table, $fieldName, $typeField, $typeKey);
                    if (null !== $suggested) {
                        $info['suggested'] = $suggested;
                    }
                }
            }
            if ($this->tcaCompatibilityService->isRelationalFieldConfig($config) && !empty($config['foreign_table'])) {
                $info['childTable'] = $config['foreign_table'];
                $info['childTableLabel'] = $this->tcaLabel->getTableLabel($config['foreign_table']);
            }

            $fields[] = $info;
        }

        $text = sprintf('Schema for `%s`', $table);
        if ('' !== $type) {
            $text .= sprintf(' (type: %s)', $type);
        }
        $text .= ":\n\n";
        $text .= sprintf("**Label:** %s | **Writable:** %s\n\n", $this->tcaLabel->getTableLabel($table), $this->recordAccess->hasTableWriteAccess($table) ? 'yes' : 'no');
        $text .= sprintf("**Fields (%d):**\n\n", count($fields));

        foreach ($fields as $f) {
            $typeStr = $f['type'].(isset($f['renderType']) ? '/'.$f['renderType'] : '');
            $flags = [];
            if ($f['required'] ?? false) {
                $flags[] = 'required';
            }
            if ($f['isRichText'] ?? false) {
                $flags[] = 'richtext:'.($f['richtextConfiguration'] ?? 'default');
            }
            if (isset($f['maxLength'])) {
                $flags[] = 'max:'.$f['maxLength'];
            }
            if (isset($f['default'])) {
                $flags[] = 'default:'.$f['default'];
            }
            if (isset($f['appearance'])) {
                $flags[] = 'appearance: '.$f['appearance'];
            }
            if (isset($f['childTable'])) {
                $flags[] = 'children:'.$f['childTable'];
            }
            if (isset($f['suggested'])) {
                $flags[] = sprintf('suggested:%s (used %d×)', $f['suggested']['value'], $f['suggested']['count']);
            }
            $flagStr = !empty($flags) ? ' ('.implode(', ', $flags).')' : '';
            $text .= sprintf("- `%s` [%s] — %s%s\n", $f['name'], $typeStr, $f['label'], $flagStr);

            if (!empty($f['options'])) {
                foreach ($f['options'] as $opt) {
                    $text .= sprintf("    - `%s`: %s\n", $opt['value'], $opt['label']);
                }
            }
        }

        $tabs = $this->buildTabStructure($table, $typeKey);
        if ([] !== $tabs) {
            $text .= "\n**Layout (tabs → fields):**\n\n";
            foreach ($tabs as $tabLabel => $tabFields) {
                $text .= sprintf("- **%s**: %s\n", $tabLabel, implode(', ', $tabFields));
            }
        }

        return $this->textResult($text);
    }

    /**
     * @param array<string, mixed> $appearance
     */
    private function summarizeAppearance(array $appearance): string
    {
        $keys = ['collapseAll', 'expandSingle', 'levelLinksPosition', 'useSortable', 'showPossibleLocalizationRecords', 'enabledControls'];
        $parts = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $appearance)) {
                continue;
            }
            $value = $appearance[$key];
            $parts[] = sprintf('%s=%s', $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return [] !== $parts ? implode(' ', $parts) : 'configured';
    }

    /**
     * @return null|array{value: string, count: int}
     */
    private function suggestCommonValue(string $table, string $field, ?string $typeField, ?string $typeValue): ?array
    {
        try {
            return $this->recordRepository->mostCommonValue(
                $table,
                $field,
                null !== $typeValue ? $typeField : null,
                $typeValue,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('GetRecordSchemaTool: value suggestion failed', [
                'table' => $table,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function buildTabStructure(string $table, ?string $typeKey): array
    {
        try {
            $tca = $this->tcaCompatibilityService->getRawConfiguration($table);
            $types = $tca['types'] ?? [];
            if ([] === $types) {
                return [];
            }

            $resolvedType = (null !== $typeKey && isset($types[$typeKey])) ? $typeKey : (string) array_key_first($types);
            $showitem = (string) ($types[$resolvedType]['showitem'] ?? '');
            if ('' === $showitem) {
                return [];
            }

            $palettes = $tca['palettes'] ?? [];
            $tabs = [];
            $currentTab = $this->tcaLabel->resolveLabel('LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general') ?: 'General';
            $tabs[$currentTab] = [];

            foreach (explode(',', $showitem) as $token) {
                $token = trim($token);
                if ('' === $token) {
                    continue;
                }
                $parts = array_map('trim', explode(';', $token));

                if ('--div--' === $parts[0]) {
                    $currentTab = isset($parts[1]) && '' !== $parts[1] ? ($this->tcaLabel->resolveLabel($parts[1]) ?: $parts[1]) : 'Tab';
                    $tabs[$currentTab] ??= [];

                    continue;
                }

                if ('--palette--' === $parts[0]) {
                    $paletteName = $parts[2] ?? '';
                    $paletteShowitem = (string) ($palettes[$paletteName]['showitem'] ?? '');
                    foreach (explode(',', $paletteShowitem) as $pField) {
                        $pField = trim(explode(';', trim($pField))[0]);
                        if ('' !== $pField && '--linebreak--' !== $pField) {
                            $tabs[$currentTab][] = $pField;
                        }
                    }

                    continue;
                }

                if ('' !== $parts[0]) {
                    $tabs[$currentTab][] = $parts[0];
                }
            }

            return array_filter($tabs, static fn (array $f): bool => [] !== $f);
        } catch (\Throwable $e) {
            $this->logger->warning('GetRecordSchemaTool: tab-structure introspection failed', [
                'table' => $table,
                'type' => $typeKey,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
