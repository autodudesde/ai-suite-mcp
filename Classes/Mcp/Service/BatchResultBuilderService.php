<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

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
     * @param iterable<mixed>                                          $items
     * @param callable(mixed, int): array{message: string, uid?: ?int} $handler
     * @param string                                                   $summaryNoun e.g. "record(s)", "copy/copies"
     */
    public function run(iterable $items, string $summaryNoun, callable $handler): CallToolResult
    {
        return new CallToolResult([new TextContent($this->renderText($items, $summaryNoun, $handler))]);
    }

    /**
     * @param iterable<mixed>                                          $items
     * @param callable(mixed, int): array{message: string, uid?: ?int} $handler same contract as run()
     */
    public function renderText(iterable $items, string $summaryNoun, callable $handler): string
    {
        $lines = [];
        $succeeded = [];
        $hadError = false;
        $count = 0;

        foreach ($items as $item) {
            ++$count;
            $index = $count;

            try {
                $outcome = $handler($item, $index);
                $lines[] = sprintf('#%d: ✅ %s', $index, $outcome['message']);
                if (null !== ($outcome['uid'] ?? null)) {
                    $succeeded[] = $outcome['uid'];
                }
            } catch (InsufficientPermissionException $e) {
                $hadError = true;
                $this->logger->warning('Batch item skipped — insufficient permission', ['index' => $index, 'message' => $e->getMessage()]);
                $lines[] = sprintf('#%d: ⛔ %s', $index, $e->getMessage());
            } catch (InvalidParameterException $e) {
                $hadError = true;
                $this->logger->warning('Batch item rejected — invalid input', ['index' => $index, 'message' => $e->getMessage()]);
                $lines[] = sprintf('#%d: ❌ %s', $index, $e->getMessage());
            } catch (\RuntimeException $e) {
                $hadError = true;
                $this->logger->error('Batch item failed', ['index' => $index, 'message' => $e->getMessage()]);
                $lines[] = sprintf('#%d: ❌ %s', $index, $e->getMessage());
            }
        }

        $text = sprintf("## Batch result: %d %s\n\n", $count, $summaryNoun);
        $text .= implode("\n", $lines);

        if ($hadError) {
            $text .= "\n\n";
            $text .= [] !== $succeeded
                ? sprintf(
                    '⚠️ Batch finished with errors. Succeeded (persisted) UID(s): %s. Only re-send the failed items.',
                    implode(', ', $succeeded),
                )
                : '⚠️ Batch finished with errors. Nothing was persisted.';
        }

        return $text;
    }
}
