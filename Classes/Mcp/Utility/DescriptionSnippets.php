<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

/**
 * Shared fragments for tool descriptions.
 *
 * The former APPROACH_A / APPROACH_B constants are gone: a description states what the tool does,
 * not which of two workflows the model should pick. The credit cost is now carried by the
 * `(costs credits)` tag, the persist workflow by the operating guidelines.
 */
class DescriptionSnippets
{
    public const COSTS_CREDITS = '(costs credits)';

    public const BATCH_ASYNC = 'Async — returns a task ID instead of a result.';
}
