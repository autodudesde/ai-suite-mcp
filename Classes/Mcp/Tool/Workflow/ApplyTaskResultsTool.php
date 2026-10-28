<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Service\TranslationService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;

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
            return $this->textError(sprintf(
                'Batch is still running (%s pending). Call readTaskStatus(taskId: "%s") — it collects the '
                .'finished results — and call this again once it reports every task as finished.',
                $this->outputFormatter->countOf($pending, 'task'),
                $taskId,
            ));
        }

        if (!$isTranslationBatch) {
            return $this->textError(
                'This batch is not a translation batch. Metadata suggestions are persisted via writeRecords.',
            );
        }

        $editable = [];
        $notEditable = [];
        foreach ($tasks as $task) {
            if ($this->recordAccess->canEditRecord((string) $task['table_name'], (int) $task['table_uid'])) {
                $editable[] = $task;
            } else {
                $notEditable[] = sprintf('%s:%d', $task['table_name'], $task['table_uid']);
            }
        }

        $blocked = [];
        $result = $this->translationService->applyBatchTranslationResults(
            $editable,
            function (string $table, int $uid, array $fields) use (&$blocked): array {
                $report = $this->mcpToolContext->dataHandlerSanitizer->sanitizeFieldsWithReport(
                    $table,
                    $fields,
                    null,
                    BackendUtility::getRecordWSOL($table, $uid) ?? [],
                );
                foreach ($report['blocked'] as $field) {
                    $blocked[] = sprintf('%s:%d.%s', $table, $uid, $field);
                }

                return $report['data'];
            },
        );

        $text = sprintf("## Batch translations applied: `%s`\n\n", $taskId);
        $text .= sprintf("**Applied:** %d\n", $result['applied']);

        if ($result['skipped'] > 0) {
            $text .= sprintf("**Skipped (nothing to apply):** %d\n", $result['skipped']);
        }
        if ($result['failed'] > 0) {
            $text .= sprintf("**Failed:** %d\n", $result['failed']);
        }
        if ([] !== $notEditable) {
            $text .= sprintf("**Not written, you may not edit:** %s\n", implode(', ', array_unique($notEditable)));
        }
        if ([] !== $blocked) {
            $text .= sprintf("**Not written, raw HTML is not allowed through MCP (mcpAllowRawHtmlWrite):** %s\n", implode(', ', $blocked));
        }

        if (!empty($result['errors'])) {
            $text .= "\n### Errors\n";
            foreach ($result['errors'] as $error) {
                $text .= "- {$error}\n";
            }
            $text .= "\nFailed tasks are kept and marked for retry; they were not discarded.\n";
        } elseif ($result['applied'] > 0) {
            $text .= "\nAll translations applied successfully.";
        } else {
            $text .= "\nNothing was applied.";
        }

        $text .= "\n\n**Note:** Translated records are hidden by default (TYPO3 standard). Use `readPageContent` with `includeHidden: true` to verify.";

        return $this->textResult($text);
    }
}
