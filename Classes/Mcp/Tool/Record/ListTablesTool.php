<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ListTablesTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;
    protected bool $readOnlyHint = true;

    public function getName(): string
    {
        return 'listTables';
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
        foreach ($this->tcaCompatibilityService->getAllTableNames() as $table) {
            if (!$this->recordAccess->hasTableReadAccess($table)) {
                continue;
            }
            $ext = str_starts_with($table, 'tx_') ? explode('_', $table, 3)[1] ?? 'Other' : 'TYPO3 Core';

            $tables[$ext][] = [
                'table' => $table,
                'label' => $this->tcaLabel->getTableLabel($table),
                'readOnly' => !$this->recordAccess->hasTableWriteAccess($table),
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

        return $this->textResult($text);
    }
}
