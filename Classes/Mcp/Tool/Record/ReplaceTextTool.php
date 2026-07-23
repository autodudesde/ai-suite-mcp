<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReplaceTextTool extends AbstractSafeEditTool
{
    public function getName(): string
    {
        return 'replaceText';
    }

    public function getDescription(): string
    {
        return 'Replace a literal text fragment inside a single field of an existing record (writes). For small '
            .'corrections — a typo, a dash, one word — where resending the whole field via writeRecords would be '
            .'wasteful. See the schema for match rules.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'TCA table name (e.g. tt_content, pages).'],
                'uid' => ['type' => 'integer', 'description' => 'UID of the record to edit.'],
                'field' => ['type' => 'string', 'description' => 'Field to edit (must be writable — see readRecordSchema).'],
                'search' => ['type' => 'string', 'description' => 'Literal text to find (not a regular expression). Matched against the raw stored value, so in an RTE/HTML field a phrase that spans tags will not match.'],
                'replace' => ['type' => 'string', 'description' => 'Replacement text.'],
                'all' => ['type' => 'boolean', 'default' => false, 'description' => 'Replace every occurrence. Default false = require a single unique match.'],
            ],
            'required' => ['table', 'uid', 'field', 'search', 'replace'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $uid = (int) $params['uid'];
        $field = (string) $params['field'];
        $search = (string) $params['search'];
        $replace = (string) $params['replace'];
        $all = (bool) ($params['all'] ?? false);

        $current = $this->loadEditableField($table, $uid, $field)['value'];
        $oldSnippet = $this->snippet($current, $search);

        $applied = $this->applyReplacement($current, $search, $replace, $all);
        $result = $this->recordWrite->update($table, $uid, [$field => $applied['result']]);

        $newSnippet = $this->snippet($applied['result'], $replace);

        $text = sprintf(
            "## Replaced %d occurrence(s) in %s:%d `%s`\n\n- **before:** %s\n- **after:** %s",
            $applied['count'],
            $this->tcaLabel->getTableLabel($table),
            $uid,
            $field,
            $oldSnippet,
            $newSnippet,
        );

        if ([] !== $result->strippedFields) {
            $text .= sprintf("\n\n> note: HTML removed from non-RTE field(s): %s", implode(', ', $result->strippedFields));
        }

        return $this->textResult($text);
    }
}
