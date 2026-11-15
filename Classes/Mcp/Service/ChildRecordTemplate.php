<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

class ChildRecordTemplate
{
    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public function render(string $table, int $uid, int $pid, array $config, ?string $fieldname = null): string
    {
        $childTable = (string) ($config['foreign_table'] ?? '');
        $fields = [];
        $selector = (string) ($config['foreign_selector'] ?? '');
        if ('' !== $selector) {
            $fields[] = sprintf('"%s": <uid of the %s record>', $selector, (string) ($this->tcaCompatibilityService->getFieldConfiguration($childTable, $selector)['allowed'] ?? 'selected'));
        }
        $fields[] = sprintf('"%s": %d', (string) ($config['foreign_field'] ?? ''), $uid);
        $tableField = (string) ($config['foreign_table_field'] ?? '');
        if ('' !== $tableField) {
            $fields[] = sprintf('"%s": "%s"', $tableField, $table);
        }
        foreach (is_array($config['foreign_match_fields'] ?? null) ? $config['foreign_match_fields'] : [] as $matchField => $matchValue) {
            if ((string) $matchField === $tableField || !is_scalar($matchValue)) {
                continue;
            }
            $value = 'fieldname' === (string) $matchField && null !== $fieldname ? $fieldname : (string) $matchValue;
            $fields[] = sprintf('"%s": "%s"', (string) $matchField, $value);
        }

        return sprintf('{"table": "%s", "pid": %d, "fields": {%s}}', $childTable, $pid, implode(', ', $fields));
    }
}
