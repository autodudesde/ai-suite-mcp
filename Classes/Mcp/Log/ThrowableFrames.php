<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Log;

final class ThrowableFrames
{
    private const MAX_FRAMES = 20;

    /**
     * @return list<string>
     */
    public static function of(\Throwable $throwable): array
    {
        $frames = [];
        foreach (array_slice($throwable->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $frames[] = sprintf(
                '%s:%d %s%s%s()',
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? 0,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'],
            );
        }

        return $frames;
    }
}
