<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetRecordSchemaTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;

    public function getName(): string
    {
        return 'getRecordSchema';
    }

    public function getDescription(): string
    {
        return 'Get field schema for a table, optionally filtered by record type '
            .'(e.g. CType for tt_content). Shows labels, types, validation, select options, IRRE children. '
            .'Respects user permissions.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'Table name (e.g. tt_content, pages)'],
                'type' => ['type' => 'string', 'description' => 'Record type filter (e.g. "textmedia" for tt_content CType)'],
            ],
            'required' => ['table'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $type = (string) ($params['type'] ?? '');
        $this->validateTableReadAccess($table);

        $typeKey = ('' !== $type && $this->tcaCompatibilityService->hasSubSchema($table, $type)) ? $type : null;
        $fieldNames = $this->tcaCompatibilityService->getFieldNamesForType($table, $typeKey);

        $fields = [];
        foreach ($fieldNames as $fieldName) {
            $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($table, $typeKey, $fieldName);
            $fieldType = (string) ($config['type'] ?? '');

            if ('passthrough' === $fieldType) {
                continue;
            }
            if (!$this->canAccessField($table, $fieldName)) {
                continue;
            }

            $info = [
                'name' => $fieldName,
                'label' => $this->getFieldLabel($table, $fieldName),
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
            }
            if (\in_array($fieldType, ['select', 'radio', 'check'], true) && isset($config['items'])) {
                $info['options'] = $this->extractOptions($config['items']);
            }
            if ($this->tcaCompatibilityService->isRelationalFieldConfig($config) && !empty($config['foreign_table'])) {
                $info['childTable'] = $config['foreign_table'];
                $info['childTableLabel'] = $this->getTableLabel($config['foreign_table']);
            }

            $fields[] = $info;
        }

        $text = sprintf('Schema for `%s`', $table);
        if ('' !== $type) {
            $text .= sprintf(' (type: %s)', $type);
        }
        $text .= ":\n\n";
        $text .= sprintf("**Label:** %s | **Writable:** %s\n\n", $this->getTableLabel($table), $this->hasTableWriteAccess($table) ? 'yes' : 'no');
        $text .= sprintf("**Fields (%d):**\n\n", count($fields));

        foreach ($fields as $f) {
            $typeStr = $f['type'].(isset($f['renderType']) ? '/'.$f['renderType'] : '');
            $flags = [];
            if ($f['required'] ?? false) {
                $flags[] = 'required';
            }
            if ($f['isRichText'] ?? false) {
                $flags[] = 'richtext';
            }
            if (isset($f['maxLength'])) {
                $flags[] = 'max:'.$f['maxLength'];
            }
            if (isset($f['childTable'])) {
                $flags[] = 'children:'.$f['childTable'];
            }
            $flagStr = !empty($flags) ? ' ('.implode(', ', $flags).')' : '';
            $text .= sprintf("- `%s` [%s] — %s%s\n", $f['name'], $typeStr, $f['label'], $flagStr);

            if (!empty($f['options'])) {
                foreach ($f['options'] as $opt) {
                    $text .= sprintf("    - `%s`: %s\n", $opt['value'], $opt['label']);
                }
            }
        }

        return new CallToolResult([new TextContent($text)]);
    }

    /**
     * @param array<string, mixed> $items
     *
     * @return list<array<string, string>>
     */
    private function extractOptions(array $items): array
    {
        $options = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['value']) || '--div--' === $item['value']) {
                continue;
            }
            $options[] = ['value' => (string) $item['value'], 'label' => $this->resolveLabel($item['label'] ?? (string) $item['value'])];
        }

        return $options;
    }
}
