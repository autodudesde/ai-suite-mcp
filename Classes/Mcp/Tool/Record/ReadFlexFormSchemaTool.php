<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadFlexFormSchemaTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;
    protected bool $readOnlyHint = true;

    public function getName(): string
    {
        return 'readFlexFormSchema';
    }

    public function getDescription(): string
    {
        return 'Inner schema of a FlexForm field (default tt_content.pi_flexform): its sheets, fields, types and '
            .'select options. readRecordSchema covers the regular columns; this tool looks inside a `flex` field. '
            .'Call it before writing one — writeRecords rejects unknown sheets and fields. Respects user permissions.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'default' => 'tt_content', 'description' => 'Table name. Default: tt_content.'],
                'field' => ['type' => 'string', 'default' => 'pi_flexform', 'description' => 'FlexForm field name. Default: pi_flexform.'],
                'recordUid' => ['type' => 'integer', 'minimum' => 1, 'description' => 'UID of an existing record — needed when the data structure depends on the record type (ds_pointerField).'],
                'type' => ['type' => 'string', 'description' => 'Type hint (CType / list_type) used to resolve the data structure when no recordUid is given.'],
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) ($params['table'] ?? 'tt_content');
        $field = (string) ($params['field'] ?? 'pi_flexform');
        $recordUid = (int) ($params['recordUid'] ?? 0);
        $typeHint = (string) ($params['type'] ?? '');

        $this->recordAccess->validateTableReadAccess($table);

        $fieldTca = $this->tcaCompatibilityService->getFieldTca($table, $field);
        if ('flex' !== (string) ($fieldTca['config']['type'] ?? '')) {
            return $this->textResult(sprintf(
                'Field `%s.%s` is not a FlexForm (`flex`) field. Use readRecordSchema for regular columns.',
                $table,
                $field,
            ));
        }

        if ($recordUid > 0) {
            $row = $this->recordAccess->assertRecordReadAccess($table, $recordUid);
        } elseif ('' !== $typeHint) {
            $row = ['uid' => 0, 'pid' => 0, 'CType' => $typeHint, 'list_type' => $typeHint];
        } else {
            $row = [];
        }

        try {
            $structure = $this->tcaCompatibilityService->resolveFlexFormDataStructure($fieldTca, $table, $field, $row);
        } catch (\Throwable $e) {
            $this->logger->warning('ReadFlexFormSchemaTool: could not resolve data structure', [
                'table' => $table,
                'field' => $field,
                'recordUid' => $recordUid,
                'type' => $typeHint,
                'error' => $e->getMessage(),
            ]);

            return $this->textResult(sprintf(
                'Could not resolve the FlexForm data structure for `%s.%s`: %s. '
                .'Try passing a recordUid of an existing element or a type hint (CType / list_type).',
                $table,
                $field,
                $e->getMessage(),
            ));
        }

        $sheets = $structure['sheets'] ?? [];
        if ([] === $sheets) {
            return $this->textResult(sprintf('FlexForm `%s.%s` resolved to an empty data structure (no sheets/fields).', $table, $field));
        }

        $text = sprintf('FlexForm schema for `%s.%s`', $table, $field);
        if ($recordUid > 0) {
            $text .= sprintf(' (record uid: %d)', $recordUid);
        } elseif ('' !== $typeHint) {
            $text .= sprintf(' (type: %s)', $typeHint);
        }
        $text .= ":\n";

        $firstSheetKey = null;
        $firstFieldKey = null;
        foreach ($sheets as $sheetKey => $sheetDef) {
            if (!is_array($sheetDef)) {
                continue;
            }
            $root = $sheetDef['ROOT'] ?? [];
            $title = $this->tcaLabel->resolveLabel((string) ($root['sheetTitle'] ?? '')) ?: (string) $sheetKey;
            $text .= sprintf("\n**Sheet `%s`%s:**\n\n", (string) $sheetKey, $title !== (string) $sheetKey ? ' — '.$title : '');
            $el = $root['el'] ?? [];
            $lines = $this->renderElements($el, 0);
            $text .= '' !== $lines ? $lines : "_(no fields)_\n";

            $firstSheetKey ??= (string) $sheetKey;
            if (null === $firstFieldKey && is_array($el)) {
                foreach ($el as $k => $def) {
                    if (is_array($def) && isset($def['config'])) {
                        $firstFieldKey = (string) $k;

                        break;
                    }
                }
            }
        }

        $text .= $this->writeHint($field, $firstSheetKey, $firstFieldKey);

        return $this->textResult($text);
    }

    private function writeHint(string $field, ?string $sheetKey, ?string $fieldKey): string
    {
        $sheet = $sheetKey ?? 'sDEF';
        $hint = "\n**How to write a value** — pass this as the `".$field.'` field value to writeRecords '
            .'(only include the fields you change). It MUST be a nested JSON object, NOT a string — '
            ."do not wrap it in quotes, do not JSON-encode it and do not build the XML yourself:\n"
            ."`{\"data\": {\"<sheet>\": {\"lDEF\": {\"<field>\": {\"vDEF\": <value>}}}}}`\n"
            .'The shorthand `{"<sheet>": {"<field>": <value>}}` is accepted as well. '
            ."Sheets and fields not listed above are rejected.\n";
        if (null !== $fieldKey) {
            $example = ['data' => [$sheet => ['lDEF' => [$fieldKey => ['vDEF' => '…']]]]];
            $hint .= 'Example for `'.$fieldKey.'` in sheet `'.$sheet.'`: `'.json_encode($example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'`'."\n";
        }

        return $hint;
    }

    /**
     * @param array<string, mixed> $elements
     */
    private function renderElements(array $elements, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $text = '';

        foreach ($elements as $key => $def) {
            if (!is_array($def)) {
                continue;
            }

            if ('array' === (string) ($def['type'] ?? '') && !empty($def['section'])) {
                $title = $this->tcaLabel->resolveLabel((string) ($def['title'] ?? (string) $key)) ?: (string) $key;
                $text .= sprintf("%s- `%s` [section — repeatable] — %s\n", $indent, (string) $key, $title);
                foreach (($def['el'] ?? []) as $containerKey => $container) {
                    if (!is_array($container)) {
                        continue;
                    }
                    $cTitle = $this->tcaLabel->resolveLabel((string) ($container['title'] ?? (string) $containerKey)) ?: (string) $containerKey;
                    $text .= sprintf("%s  - container `%s` — %s\n", $indent, (string) $containerKey, $cTitle);
                    $text .= $this->renderElements($container['el'] ?? [], $depth + 2);
                }

                continue;
            }

            $config = $def['config'] ?? null;
            if (!is_array($config)) {
                continue;
            }

            $type = (string) ($config['type'] ?? '');
            $label = $this->tcaLabel->resolveLabel((string) ($def['label'] ?? (string) $key)) ?: (string) $key;

            $flags = [];
            if ($this->isRequired($config)) {
                $flags[] = 'required';
            }
            if (isset($config['default']) && '' !== (string) $config['default']) {
                $flags[] = 'default:'.(string) $config['default'];
            }
            $flagStr = [] !== $flags ? ' ('.implode(', ', $flags).')' : '';

            $text .= sprintf("%s- `%s` [%s] — %s%s\n", $indent, (string) $key, '' !== $type ? $type : 'unknown', $label, $flagStr);

            if (\in_array($type, ['select', 'radio', 'check'], true) && isset($config['items']) && is_array($config['items'])) {
                foreach ($this->tcaLabel->buildSelectOptions($config['items']) as $opt) {
                    $text .= sprintf("%s    - `%s`: %s\n", $indent, $opt['value'], $opt['label']);
                }
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isRequired(array $config): bool
    {
        if (!empty($config['required'])) {
            return true;
        }

        $eval = (string) ($config['eval'] ?? '');

        return '' !== $eval && in_array('required', array_map('trim', explode(',', $eval)), true);
    }
}
