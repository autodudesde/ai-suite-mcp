<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetTablesTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;

    public function getName(): string
    {
        return 'getTables';
    }

    public function getDescription(): string
    {
        return 'List all database tables the current user can access, grouped by extension. '
            .'Use this to discover which tables are available for readRecords/writeRecords.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $tables = [];
        foreach (array_keys($GLOBALS['TCA'] ?? []) as $table) {
            $table = (string) $table;
            if (!$this->hasTableReadAccess($table)) {
                continue;
            }
            $ext = str_starts_with($table, 'tx_') ? explode('_', $table, 3)[1] ?? 'Other' : 'TYPO3 Core';

            $tables[$ext][] = [
                'table' => $table,
                'label' => $this->getTableLabel($table),
                'readOnly' => !$this->hasTableWriteAccess($table),
                'hasLanguage' => $this->tcaCompatibilityService->isLanguageAware($table),
            ];
        }

        ksort($tables);
        $text = "Available tables:\n\n";
        foreach ($tables as $ext => $extTables) {
            $text .= sprintf("### %s\n", $ext);
            foreach ($extTables as $info) {
                $flags = [];
                if ($info['readOnly']) {
                    $flags[] = 'read-only';
                }
                if ($info['hasLanguage']) {
                    $flags[] = 'translatable';
                }
                $flagStr = !empty($flags) ? ' ('.implode(', ', $flags).')' : '';
                $text .= sprintf("- `%s` — %s%s\n", $info['table'], $info['label'], $flagStr);
            }
            $text .= "\n";
        }

        return new CallToolResult([new TextContent($text)]);
    }
}
