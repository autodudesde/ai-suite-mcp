<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\Dto\BatchOutcome;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\Log\LoggerInterface;

class BatchResultBuilderService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * `succeededUids` alone cannot be mapped back onto the input items once a single
     * item fails, because failed items leave no entry. Handlers that report `table`
     * (and optionally `action`) therefore also get a `records` list of change
     * descriptors, which is what audit consumers such as ChEddi's change tracker read.
     *
     * @param iterable<mixed>                                                                             $items
     * @param callable(mixed, int): array{message: string, uid?: ?int, table?: ?string, action?: ?string} $handler
     * @param string                                                                                      $summaryNoun e.g. "record(s)", "copy/copies"
     */
    public function run(iterable $items, string $summaryNoun, callable $handler): CallToolResult
    {
        $outcome = $this->build($items, $summaryNoun, $handler);

        $batch = [
            'total' => $outcome->total,
            'succeededUids' => $outcome->succeeded,
            'failedCount' => $outcome->failedCount,
        ];
        if ([] !== $outcome->records) {
            $batch['records'] = $outcome->records;
        }

        $structured = ['batch' => $batch];
        if ($outcome->hadError()) {
            $structured['error'] = [
                'type' => ($outcome->errorType ?? McpErrorType::DataHandlerError)->value,
                'failedCount' => $outcome->failedCount,
            ];
        }

        return new CallToolResult(
            [new TextContent($outcome->text)],
            isError: $outcome->hadError(),
            structuredContent: $structured,
        );
    }

    /**
     * @param iterable<mixed>                                          $items
     * @param callable(mixed, int): array{message: string, uid?: ?int} $handler same contract as run()
     */
    public function renderText(iterable $items, string $summaryNoun, callable $handler): string
    {
        return $this->build($items, $summaryNoun, $handler)->text;
    }

    /**
     * @param iterable<mixed>                                                                             $items
     * @param callable(mixed, int): array{message: string, uid?: ?int, table?: ?string, action?: ?string} $handler
     */
    public function build(iterable $items, string $summaryNoun, callable $handler): BatchOutcome
    {
        $lines = [];
        $succeeded = [];
        $records = [];
        $failedCount = 0;
        $count = 0;
        $errorType = null;

        foreach ($items as $item) {
            ++$count;
            $index = $count;

            try {
                $outcome = $handler($item, $index);
                $lines[] = sprintf('#%d: ✅ %s', $index, $outcome['message']);
                if (null !== ($outcome['uid'] ?? null)) {
                    $succeeded[] = $outcome['uid'];

                    $table = $outcome['table'] ?? null;
                    if (is_string($table) && '' !== $table) {
                        $records[] = [
                            'table' => $table,
                            'uid' => $outcome['uid'],
                            'action' => (string) ($outcome['action'] ?? 'update'),
                        ];
                    }
                }
            } catch (InsufficientPermissionException $e) {
                ++$failedCount;
                $errorType ??= $e->getErrorType();
                $this->logger->warning('Batch item skipped — insufficient permission', ['index' => $index, 'message' => $e->getMessage()]);
                $lines[] = sprintf('#%d: ⛔ %s', $index, $e->getMessage());
            } catch (InvalidParameterException $e) {
                ++$failedCount;
                $errorType ??= $e->getErrorType();
                $this->logger->warning('Batch item rejected — invalid input', ['index' => $index, 'message' => $e->getMessage()]);
                $lines[] = sprintf('#%d: ❌ %s', $index, $e->getMessage());
            } catch (\RuntimeException $e) {
                ++$failedCount;
                $errorType ??= McpErrorType::DataHandlerError;
                $this->logger->error('Batch item failed', ['index' => $index, 'message' => $e->getMessage()]);
                $lines[] = sprintf('#%d: ❌ %s', $index, $e->getMessage());
            }
        }

        $hadError = $failedCount > 0;

        // The verdict belongs in the first line. A small model skims the head of a tool result, and
        // "## Batch result" above a list that mixes ✅ and ❌ reads as success -- measured: gpt-5.4-nano
        // reported "done" after writing one of two records. The success-path header stays byte-identical.
        $text = $hadError
            ? sprintf(
                "## Batch FAILED: %d of %d %s could not be written, %d succeeded\n\n",
                $failedCount,
                $count,
                $summaryNoun,
                $count - $failedCount,
            )
            : sprintf("## Batch result: %d %s\n\n", $count, $summaryNoun);
        $text .= implode("\n", $lines);

        if ($hadError) {
            $text .= "\n\n";
            $text .= [] !== $succeeded
                ? sprintf(
                    '❌ This call did not succeed. %d %s were NOT written. Persisted UID(s): %s. Re-send only the corrected failed items.',
                    $failedCount,
                    $summaryNoun,
                    implode(', ', $succeeded),
                )
                : '❌ This call failed. Nothing was written.';
        }

        return new BatchOutcome($text, $count, $succeeded, $failedCount, $records, $errorType);
    }
}
