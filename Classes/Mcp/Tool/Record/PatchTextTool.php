<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class PatchTextTool extends AbstractSafeEditTool
{
    private const MAX_REPLACEMENTS = 200;

    public function getName(): string
    {
        return 'patchText';
    }

    public function getDescription(): string
    {
        return 'Apply several literal search/replace edits to one field of an existing record in a single write '
            .'(writes). For multiple small corrections without resending the whole field. Atomic: if any '
            .'replacement fails, nothing is written.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'TCA table name (e.g. tt_content, pages).'],
                'uid' => ['type' => 'integer', 'description' => 'UID of the record to edit.'],
                'field' => ['type' => 'string', 'description' => 'Field to edit (must be writable — see readRecordSchema).'],
                'replacements' => [
                    'type' => 'array',
                    'description' => 'Ordered list of edits, applied top to bottom on the running raw stored value. Each: {search, replace, all?}. `all` defaults to false, meaning the search text must occur exactly once.',
                    'items' => ['type' => 'object'],
                ],
                'normalizeWhitespace' => ['type' => 'boolean', 'default' => true, 'description' => 'Ignore line-ending and spacing differences when locating each match. The replacement is spliced into the original, so text outside the match keeps its exact bytes.'],
            ],
            'required' => ['table', 'uid', 'field', 'replacements'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $uid = (int) $params['uid'];
        $field = (string) $params['field'];
        $replacements = $params['replacements'] ?? [];

        if (!is_array($replacements) || [] === $replacements) {
            return $this->textError('replacements must be a non-empty array.');
        }
        if (count($replacements) > self::MAX_REPLACEMENTS) {
            return $this->textError(sprintf('Too many replacements (max %d).', self::MAX_REPLACEMENTS));
        }

        $this->normalizeWhitespace = (bool) ($params['normalizeWhitespace'] ?? true);
        $value = $this->loadEditableField($table, $uid, $field)['value'];

        $applied = 0;
        foreach ($replacements as $i => $replacement) {
            if (!is_array($replacement)) {
                throw new InvalidParameterException(sprintf('Replacement #%d must be an object with search/replace.', (int) $i + 1));
            }
            $search = (string) ($replacement['search'] ?? '');
            $replace = (string) ($replacement['replace'] ?? '');
            $all = (bool) ($replacement['all'] ?? false);

            try {
                $outcome = $this->applyReplacement($value, $search, $replace, $all, $this->normalizeWhitespace);
            } catch (InvalidParameterException $e) {
                throw (new InvalidParameterException(sprintf('Replacement #%d: %s', (int) $i + 1, $e->getMessage())))
                    ->withErrorType($e->getErrorType())
                ;
            }
            $value = $outcome['result'];
            $applied += $outcome['count'];
        }

        $result = $this->recordWrite->update($table, $uid, [$field => $value]);

        $text = sprintf(
            '## Applied %d replacement(s) (%d occurrence(s)) to %s:%d `%s`',
            count($replacements),
            $applied,
            $this->tcaLabel->getTableLabel($table),
            $uid,
            $field,
        );

        if ([] !== $result->strippedFields) {
            $text .= sprintf("\n\n> note: HTML removed from non-RTE field(s): %s", implode(', ', $result->strippedFields));
        }

        return $this->structuredResult($text, ['batch' => ['records' => [['table' => $table, 'uid' => $uid]]]]);
    }
}
