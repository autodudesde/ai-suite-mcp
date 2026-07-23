<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadTaskResultsTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readTaskResults';
    }

    public function getDescription(): string
    {
        return 'Read the generated suggestions of a finished batch operation. '
            .'Metadata suggestions are persisted with writeRecords; translations with applyTaskResults.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string', 'description' => 'The task/batch ID (parent UUID).'],
                'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Skip first N result groups (pagination).'],
                'limit' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50, 'description' => 'Max result groups to return.'],
            ],
            'required' => ['taskId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $taskId = (string) $params['taskId'];
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $limit = max(1, min(50, (int) ($params['limit'] ?? 10)));

        if ('' === $taskId) {
            return $this->textError('taskId is required.');
        }

        $tasks = $this->backgroundTaskRepository->findByParentUuid($taskId);

        if (empty($tasks)) {
            return $this->textError(sprintf('No tasks found for ID: %s', $taskId));
        }

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

        return $this->buildResultsPreview($tasks, $taskId, $offset, $limit, $isTranslationBatch);
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
                $resultGroups[$key]['fields'][$task['column']] = $this->extractSuggestions(\is_array($answer) ? $answer : []);
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
            $text .= 'To write these translations into the localization records, call `applyTaskResults` with the same taskId.';
        }

        return $this->textResult($text);
    }

    /**
     * @param array<mixed> $answer
     *
     * @return list<string>
     */
    private function extractSuggestions(array $answer): array
    {
        $type = $answer['type'] ?? null;
        $body = \is_array($answer['body'] ?? null) ? $answer['body'] : [];

        if ('Metadata' === $type && \is_array($body['metadataResult'] ?? null)) {
            return array_values(array_filter($body['metadataResult'], \is_string(...)));
        }
        if ('Error' === $type) {
            return [(string) ($body['message'] ?? 'Generation failed.')];
        }
        if ('Translate' === $type && \is_array($body['translationResults'] ?? null)) {
            return $this->stringLeaves($body['translationResults']);
        }

        return $this->stringLeaves($answer);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function stringLeaves(array $data): array
    {
        $strings = [];
        array_walk_recursive($data, static function ($value) use (&$strings): void {
            if (\is_string($value) && '' !== trim($value)) {
                $strings[] = $value;
            }
        });

        return $strings;
    }
}
