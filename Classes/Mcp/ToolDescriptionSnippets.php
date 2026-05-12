<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

/**
 * Canonical description snippets shared across MCP tool descriptions.
 *
 * Single source of truth for recurring phrases — ensures consistent wording
 * across all tools. Change once here, applies everywhere.
 */
class ToolDescriptionSnippets
{
    /**
     * Approach A: external AI intro.
     * Usage: '(A) Use this tool using an external AI model — costs credits.'
     * Append tool-specific details after this.
     */
    public const APPROACH_A = '(A) Use this tool using an external AI model — costs credits. ';

    /**
     * Approach A: preview + persist.
     * Appended after the tool-specific Approach A details.
     */
    public const APPROACH_A_PREVIEW_AND_PERSIST = 'Approach A returns a preview directly in the response — display it to the user (no additional tool call needed). '
        .'After explicit user approval, persist via writeRecords.';

    /**
     * Approach B: manual workflow suffix.
     * Prepend the tool-specific steps (e.g. "Read content via readRecords, rewrite it yourself").
     * This provides the standard ending: → previewRecords → display → approval → writeRecords.
     */
    public const APPROACH_B_PERSIST = '→ previewRecords → display the preview to the user → after explicit user approval → writeRecords — no credits.';

    /**
     * Batch tool: async flow suffix.
     * Describes the full async lifecycle: task ID → getTaskStatus → getTaskResults → user approval → writeRecords.
     */
    public const BATCH_ASYNC_FLOW = 'Runs asynchronously — returns a task ID. '
        .'Check progress via getTaskStatus (NON-BLOCKING: continue with other work), then retrieve results via getTaskResults. '
        .'Results are suggestions — display to user, then persist approved results via writeRecords.';
}
