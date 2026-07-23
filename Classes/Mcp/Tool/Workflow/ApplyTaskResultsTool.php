<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Service\TranslationService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Persists the translations of a finished batch into the localization records.
 *
 * This used to be `getTaskResults(apply: true)`. MCP tool annotations are static, so a single
 * tool whose `apply` flag flips it from a read into a DataHandler write cannot describe itself
 * honestly: it advertised `readOnlyHint: true`, which made the chat drawer auto-execute it
 * without a confirmation dialog, and its (missing) scope-map entry let a read-only token write.
 * Splitting read from write is what makes the annotations — and therefore both the host-side
 * approval gate and the OAuth scope gate — true.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class ApplyTaskResultsTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:write';
    protected bool $readOnlyHint = false;
    protected bool $destructiveHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly TranslationService $translationService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'applyTaskResults';
    }

    public function getDescription(): string
    {
        return 'Write the translations of a finished translation batch into the localization records (writes). '
            .'Use readTaskResults to read the suggestions without writing them.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string', 'description' => 'The task/batch ID (parent UUID).'],
            ],
            'required' => ['taskId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $taskId = (string) $params['taskId'];
        if ('' === $taskId) {
            return $this->textError('taskId is required.');
        }

        $tasks = $this->backgroundTaskRepository->findByParentUuid($taskId);
        if (empty($tasks)) {
            return $this->textError(sprintf('No tasks found for ID: %s', $taskId));
        }

        $pending = 0;
        $isTranslationBatch = false;
        foreach ($tasks as $task) {
            if ('pending' === (string) ($task['status'] ?? 'pending')) {
                ++$pending;
            }
            if ('translation' === ($task['type'] ?? '') || 'page-translation' === ($task['scope'] ?? '')) {
                $isTranslationBatch = true;
            }
        }

        if ($pending > 0) {
            return $this->textError(sprintf('Batch is still running (%d tasks pending).', $pending));
        }

        if (!$isTranslationBatch) {
            return $this->textError(
                'This batch is not a translation batch. Metadata suggestions are persisted via writeRecords.',
            );
        }

        $result = $this->translationService->applyBatchTranslationResults($tasks);

        $text = sprintf("## Batch translations applied: `%s`\n\n", $taskId);
        $text .= sprintf("**Applied:** %d\n", $result['applied']);

        if (!empty($result['errors'])) {
            $text .= "\n### Errors\n";
            foreach ($result['errors'] as $error) {
                $text .= "- {$error}\n";
            }
        } else {
            $text .= "\nAll translations applied successfully.";
        }

        $text .= "\n\n**Note:** Translated records are hidden by default (TYPO3 standard). Use `readPageContent` with `includeHidden: true` to verify.";

        return $this->textResult($text);
    }
}
