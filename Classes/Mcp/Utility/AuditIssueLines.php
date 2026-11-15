<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

final class AuditIssueLines
{
    public const MAX_FINDINGS = 12;
    public const MAX_A11Y_GROUPS = 10;

    /**
     * @param list<string> $fixedIds
     *
     * @return list<string>
     */
    public static function findings(mixed $issues, array $fixedIds = []): array
    {
        $lines = [];
        foreach (array_slice(is_array($issues) ? $issues : [], 0, self::MAX_FINDINGS) as $issue) {
            $id = (string) ($issue['id'] ?? '');
            $lines[] = sprintf(
                '- [%s/%s] %s: %s%s',
                (string) ($issue['severity'] ?? ''),
                (string) ($issue['fixability'] ?? ''),
                $id,
                (string) ($issue['message'] ?? ''),
                in_array($id, $fixedIds, true) ? ' (already fixed in the Audit module)' : '',
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public static function accessibilityGroups(mixed $topIssues): array
    {
        $lines = [];
        foreach (array_slice(is_array($topIssues) ? $topIssues : [], 0, self::MAX_A11Y_GROUPS) as $issue) {
            $lines[] = sprintf(
                '- %dx %s (%s): %s — e.g. `%s`',
                (int) ($issue['count'] ?? 0),
                (string) ($issue['code'] ?? ''),
                (string) ($issue['impact'] ?? $issue['type'] ?? ''),
                (string) ($issue['message'] ?? ''),
                (string) ($issue['sampleSelector'] ?? ''),
            );
        }

        return $lines;
    }
}
