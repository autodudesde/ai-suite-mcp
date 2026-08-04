<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuite\Service\RichtextPresetService;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\FieldFormatHintService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RawMarkupPolicyService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadRecordSchemaTool extends AbstractDataTool
{
    private const MAX_LISTED_TAGS = 20;

    protected ?string $requiredScope = null;
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly RecordRepository $recordRepository,
        private readonly FieldFormatHintService $fieldFormatHints,
        private readonly RawMarkupPolicyService $rawMarkupPolicy,
        private readonly RichtextPresetService $richtextPresets,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readRecordSchema';
    }

    public function getDescription(): string
    {
        return 'Field schema of a table, optionally filtered by record type (e.g. CType for tt_content). Per field: '
            .'label, type, validation, select options, IRRE children, read-only status, relation kind, and the '
            .'content kind that decides whether markup survives a write. readFlexFormSchema looks inside a `flex` field.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'Table name (e.g. tt_content, pages). Content kinds reported per field: rte = HTML honoured; html = code editor field, raw markup stored verbatim; text/plaintext = markup stripped on write; lines = line-based, see the Format note on the field; json; relation.'],
                'type' => ['type' => 'string', 'description' => 'Record type filter (e.g. "textmedia" for tt_content CType)'],
                'suggestValues' => ['type' => 'boolean', 'default' => true, 'description' => 'Suggest the most common existing value for configuration select fields (appearance, frame, layout), sampled from existing records, so a new record matches the site conventions.'],
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

        $typeKey = ('' !== $type && $this->tcaCompatibilityService->hasSubSchema($table, $type))
            ? $type
            : $this->tcaCompatibilityService->resolveDefaultSubSchemaType($table);
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
                $info['allowedTags'] = $this->richtextPresets->getAllowedTags($table, $fieldName, (string) $typeKey, $config);
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
            if ($this->tcaCompatibilityService->isRelationalFieldConfig($config)) {
                $info['relationKind'] = $fieldType;
                if (!empty($config['foreign_table'])) {
                    $info['childTable'] = $config['foreign_table'];
                    $info['childTableLabel'] = $this->tcaLabel->getTableLabel($config['foreign_table']);
                }
            }
            if (!empty($config['readOnly'])) {
                $info['readOnly'] = true;
            }
            if (isset($config['eval']) && '' !== (string) $config['eval']) {
                $info['eval'] = (string) $config['eval'];
            }
            $info['kind'] = $this->classifyFieldKind($config, $fieldType);
            if ('html' === $info['kind']) {
                $info['format'] = $this->rawMarkupPolicy->isRawMarkupWriteAllowed()
                    ? 'source code — stored verbatim, nothing is escaped or reformatted. Send complete, valid markup.'
                    : sprintf('source code — stored verbatim, but writing markup here is disabled ("%s"): such a write is rejected, not stripped.', RawMarkupPolicyService::SETTING_KEY);
            }

            $formatHint = $this->fieldFormatHints->forField($table, $typeKey, $fieldName);
            if (null !== $formatHint) {
                $info['kind'] = 'lines';
                $info['format'] = $formatHint;
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
            if ($f['readOnly'] ?? false) {
                $flags[] = 'read-only';
            }
            $flags[] = 'kind:'.$f['kind'];
            if ($f['required'] ?? false) {
                $flags[] = 'required';
            }
            if (isset($f['eval'])) {
                $flags[] = 'eval:'.$f['eval'];
            }
            if ($f['isRichText'] ?? false) {
                $flags[] = 'richtext:'.($f['richtextConfiguration'] ?? 'default');
                $flags[] = $this->renderAllowedTags($f['allowedTags'] ?? []);
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
            $flagStr = ' ('.implode(', ', $flags).')';
            $text .= sprintf("- `%s` [%s] — %s%s\n", $f['name'], $typeStr, $f['label'], $flagStr);

            if (isset($f['format'])) {
                $text .= sprintf("    - **Format:** %s\n", $f['format']);
            }

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
     * @param list<string> $tags
     */
    private function renderAllowedTags(array $tags): string
    {
        if ([] === $tags) {
            return 'allowed tags: unknown';
        }

        $shown = array_slice($tags, 0, self::MAX_LISTED_TAGS);
        $suffix = count($tags) > self::MAX_LISTED_TAGS
            ? sprintf(', +%d more', count($tags) - self::MAX_LISTED_TAGS)
            : '';

        // No parentheses: this string sits inside the parenthesised per-field flag list.
        return sprintf('tags surviving a backend save: %s%s', implode(', ', $shown), $suffix);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function classifyFieldKind(array $config, string $fieldType): string
    {
        if ($this->tcaCompatibilityService->isRelationalFieldConfig($config)) {
            return 'relation';
        }
        if ('json' === $fieldType) {
            return 'json';
        }
        if ('text' === $fieldType) {
            if ($this->tcaCompatibilityService->isRichTextFieldConfig($config)) {
                return 'rte';
            }

            return $this->tcaCompatibilityService->isRawMarkupFieldConfig($config) ? 'html' : 'text';
        }

        return 'plaintext';
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
            $this->logger->warning('ReadRecordSchemaTool: value suggestion failed', [
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
            $this->logger->warning('ReadRecordSchemaTool: tab-structure introspection failed', [
                'table' => $table,
                'type' => $typeKey,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
