<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Service\BackgroundTaskService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetTaskStatusTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BackgroundTaskService $backgroundTaskService,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'getTaskStatus';
    }

    public function getDescription(): string
    {
        return 'Check the progress of a background batch operation (e.g. from batchGenerateMetadata). '
            .'NON-BLOCKING: do NOT poll or loop — save the task ID locally, inform the user that a batch is running, '
            .'then continue with other work. Only check back when the user asks about it. '
            .'Once status is "completed", retrieve results via getTaskResults.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string', 'description' => 'The task/batch ID (parent UUID).'],
                'refresh' => ['type' => 'boolean', 'default' => true, 'description' => 'Fetch latest status from AI Suite Server before returning.'],
            ],
            'required' => ['taskId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $taskId = (string) $params['taskId'];
        $refresh = (bool) ($params['refresh'] ?? true);

        if ('' === $taskId) {
            return $this->textError('taskId is required.');
        }

        if ($refresh) {
            try {
                $this->backgroundTaskService->fetchBackgroundTaskStatus(true);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not refresh task status from server', ['error' => $e->getMessage()]);
            }
        }

        $tasks = $this->backgroundTaskRepository->findByParentUuid($taskId);

        if (empty($tasks)) {
            return $this->textError(sprintf('No tasks found for ID: %s', $taskId));
        }

        $total = count($tasks);
        $pending = 0;
        $finished = 0;
        $failed = 0;
        $errors = [];

        foreach ($tasks as $task) {
            $status = (string) ($task['status'] ?? 'pending');
            match ($status) {
                'finished' => ++$finished,
                'task-error' => ++$failed,
                default => ++$pending,
            };

            if ('task-error' === $status) {
                $errors[] = sprintf(
                    '%s:%d `%s` — %s',
                    $task['table_name'],
                    $task['table_uid'],
                    $task['column'],
                    $task['error'] ?? 'unknown error',
                );
            }
        }

        $isRunning = $pending > 0;
        $progress = round(($finished + $failed) / $total * 100);

        $text = sprintf("## Batch Status: `%s`\n\n", $taskId);
        $text .= sprintf("**Total:** %d | **Finished:** %d | **Pending:** %d | **Failed:** %d\n", $total, $finished, $pending, $failed);
        $text .= sprintf("**Progress:** %d%%\n", $progress);

        if ($isRunning) {
            $estimatedSeconds = max(30, $pending * 5);
            $text .= sprintf("\n_Still processing. Estimated wait: ~%d seconds._", $estimatedSeconds);
        }

        if (!empty($errors)) {
            $text .= "\n\n### Errors\n";
            $pagedErrors = array_slice($errors, 0, 10);
            foreach ($pagedErrors as $error) {
                $text .= $error."\n";
            }
            if (count($errors) > 10) {
                $text .= sprintf("_...and %d more errors_\n", count($errors) - 10);
            }
        }

        if (!$isRunning && $finished > 0) {
            $text .= sprintf("\n\n_Batch complete. %d results ready to retrieve._", $finished);
        }

        return $this->textResult($text);
    }
}
