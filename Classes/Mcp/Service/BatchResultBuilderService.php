<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\Dto\BatchOutcome;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\SkippedItemException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\Log\LoggerInterface;

class BatchResultBuilderService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly WorkspaceRecordService $workspaceRecords,
    ) {}

    /**
     * @param iterable<mixed>                                                                             $items
     * @param callable(mixed, int): array{message: string, uid?: ?int, table?: ?string, action?: ?string} $handler
     */
    public function run(iterable $items, string $summaryNoun, callable $handler): CallToolResult
    {
        $outcome = $this->build($items, $summaryNoun, $handler);

        return new CallToolResult(
            [new TextContent($outcome->text)],
            isError: $outcome->hadError(),
            structuredContent: $this->structuredFor($outcome),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function structuredFor(BatchOutcome $outcome): array
    {
        $batch = [
            'total' => $outcome->total,
            'succeededUids' => $outcome->succeeded,
            'failedCount' => $outcome->failedCount,
            'skippedCount' => $outcome->skipped,
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

        return $structured;
    }

    /**
     * @param iterable<mixed>                                          $items
     * @param callable(mixed, int): array{message: string, uid?: ?int} $handler
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
        $skippedCount = 0;
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
            } catch (SkippedItemException $e) {
                ++$skippedCount;
                $lines[] = sprintf('#%d: ⏭️ %s', $index, $e->getMessage());
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

        if ($hadError) {
            $text = sprintf(
                "## Batch FAILED: %d of %d %s could not be written, %d succeeded%s\n\n",
                $failedCount,
                $count,
                $summaryNoun,
                $count - $failedCount - $skippedCount,
                $skippedCount > 0 ? sprintf(', %d skipped', $skippedCount) : '',
            );
        } elseif ($skippedCount > 0) {
            $text = sprintf(
                "## Batch result: %d %s, %d written, %d skipped (nothing to do)\n\n",
                $count,
                $summaryNoun,
                $count - $skippedCount,
                $skippedCount,
            );
        } else {
            $text = sprintf("## Batch result: %d %s\n\n", $count, $summaryNoun);
        }
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

        if ([] !== $succeeded && $this->workspaceRecords->isActive()) {
            $text .= sprintf(
                "\n\nℹ️ Written to workspace %d — not visible on the live site until the workspace is published.",
                $this->workspaceRecords->getWorkspaceId(),
            );
        }

        return new BatchOutcome($text, $count, $succeeded, $failedCount, $records, $errorType, $skippedCount);
    }
}
