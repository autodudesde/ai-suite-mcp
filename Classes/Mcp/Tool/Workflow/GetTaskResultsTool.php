<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Service\TranslationService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetTaskResultsTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly TranslationService $translationService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'getTaskResults';
    }

    public function getDescription(): string
    {
        return 'Retrieve the generated suggestions from a completed batch operation. '
            .'Only call after getTaskStatus reports status "completed" — do NOT call before completion. '
            .'For translation batches: set apply=true to write translations directly to the localization records. '
            .'For metadata batches: returns suggestions — display to user, then persist approved results via writeRecords.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string', 'description' => 'The task/batch ID (parent UUID).'],
                'apply' => ['type' => 'boolean', 'default' => false, 'description' => 'For translation batches: apply results directly to localization records. Requires user confirmation before calling with apply=true.'],
                'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Skip first N result groups (pagination).'],
                'limit' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50, 'description' => 'Max result groups to return.'],
            ],
            'required' => ['taskId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $taskId = (string) $params['taskId'];
        $apply = (bool) ($params['apply'] ?? false);
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $limit = max(1, min(50, (int) ($params['limit'] ?? 10)));

        if ('' === $taskId) {
            return $this->textError('taskId is required.');
        }

        $tasks = $this->backgroundTaskRepository->findByParentUuid($taskId);

        if (empty($tasks)) {
            return $this->textError(sprintf('No tasks found for ID: %s', $taskId));
        }

        // Check if this is a translation batch
        $isTranslationBatch = false;
        $pending = 0;

        foreach ($tasks as $task) {
            $status = (string) ($task['status'] ?? 'pending');
            if ('pending' === $status) {
                ++$pending;
            }
            if ('translation' === ($task['type'] ?? '') || 'page-translation' === ($task['scope'] ?? '')) {
                $isTranslationBatch = true;
            }
        }

        if ($pending > 0) {
            return new CallToolResult([new TextContent(
                sprintf('Batch is still running (%d tasks pending). Check status first.', $pending),
            )], isError: true);
        }

        // Apply translation results if requested
        if ($apply && $isTranslationBatch) {
            return $this->applyTranslationResults($tasks, $taskId);
        }

        // Return results for review
        return $this->buildResultsPreview($tasks, $taskId, $offset, $limit, $isTranslationBatch);
    }

    /**
     * @param list<array<string, mixed>> $tasks
     */
    private function applyTranslationResults(array $tasks, string $taskId): CallToolResult
    {
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

        $text .= "\n\n**Note:** Translated records are hidden by default (TYPO3 standard). Use `getPageContent` with `includeHidden: true` to verify.";

        return $this->textResult($text);
    }

    /**
     * @param list<array<string, mixed>> $tasks
     */
    private function buildResultsPreview(array $tasks, string $taskId, int $offset, int $limit, bool $isTranslationBatch): CallToolResult
    {
        $resultGroups = [];

        foreach ($tasks as $task) {
            $status = (string) ($task['status'] ?? 'pending');

            if ('finished' === $status && !empty($task['answer'])) {
                $answer = json_decode((string) $task['answer'], true);
                $key = $task['table_name'].':'.$task['table_uid'];
                $resultGroups[$key]['table'] = $task['table_name'];
                $resultGroups[$key]['uid'] = (int) $task['table_uid'];
                $resultGroups[$key]['fields'][$task['column']] = \is_array($answer) ? $answer : [];
            }
        }

        if (empty($resultGroups)) {
            return $this->textResult('No results available for this batch.');
        }

        $groupKeys = array_keys($resultGroups);
        $totalGroups = \count($groupKeys);
        $pagedKeys = \array_slice($groupKeys, $offset, $limit);
        $end = min($offset + $limit, $totalGroups);

        $text = sprintf("## Batch Results: `%s` (%d-%d of %d)\n\n", $taskId, $offset + 1, $end, $totalGroups);

        foreach ($pagedKeys as $key) {
            $group = $resultGroups[$key];
            $text .= sprintf("### %s (UID: %d)\n", $group['table'], $group['uid']);

            foreach ($group['fields'] as $field => $suggestions) {
                $text .= sprintf("**%s:**\n", $field);
                foreach (array_values($suggestions) as $i => $suggestion) {
                    $display = \is_array($suggestion) ? json_encode($suggestion, JSON_UNESCAPED_UNICODE) : (string) $suggestion;
                    $text .= sprintf("  %d. %s\n", $i + 1, $display);
                }
                $text .= "\n";
            }
        }

        if ($end < $totalGroups) {
            $text .= sprintf("_More results available (offset: %d)._\n", $end);
        }

        if ($isTranslationBatch) {
            $text .= "\n---\n";
            $text .= 'To apply these translations, call `getTaskResults` again with `apply: true` after user confirmation.';
        }

        return $this->textResult($text);
    }
}
