<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;

class BatchEntryValidator
{
    /**
     * @param array<array-key, mixed> $entries
     * @param list<string>            $required
     * @param list<string>            $anyOf
     *
     * @throws InvalidParameterException
     */
    public function assertShape(array $entries, string $argumentName, array $required, array $anyOf = [], string $nestedContainer = ''): void
    {
        $problems = [];

        foreach (array_values($entries) as $index => $entry) {
            $position = $index + 1;

            if (!is_array($entry)) {
                $problems[] = sprintf('#%d: not an object (got %s)', $position, get_debug_type($entry));

                continue;
            }

            $missing = [];
            foreach ($required as $key) {
                if (!array_key_exists($key, $entry) || $this->isBlank($entry[$key])) {
                    $missing[] = '`'.$key.'`';
                }
            }
            if ([] !== $missing) {
                $problems[] = sprintf('#%d: missing %s', $position, implode(', ', $missing));

                continue;
            }

            if ([] !== $anyOf && !$this->hasAnyOf($entry, $anyOf)) {
                $misplaced = $this->findMisplaced($entry, $anyOf, $nestedContainer);
                $problems[] = null !== $misplaced
                    ? sprintf(
                        '#%d: `%s` sits inside `%s` but must be a sibling property of the record, next to `table`',
                        $position,
                        $misplaced,
                        $nestedContainer,
                    )
                    : sprintf(
                        '#%d: needs one of %s',
                        $position,
                        implode(' or ', array_map(static fn (string $key): string => '`'.$key.'`', $anyOf)),
                    );
            }
        }

        if ([] === $problems) {
            return;
        }

        throw new InvalidParameterException(sprintf(
            "%d of %d `%s` entries are malformed, so nothing was written:\n- %s\nCorrect all of them and send the call again.",
            count($problems),
            count($entries),
            $argumentName,
            implode("\n- ", $problems),
        ));
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string>         $keys
     */
    private function findMisplaced(array $entry, array $keys, string $nestedContainer): ?string
    {
        if ('' === $nestedContainer || !is_array($entry[$nestedContainer] ?? null)) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $entry[$nestedContainer])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string>         $keys
     */
    private function hasAnyOf(array $entry, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $entry) && !$this->isBlank($entry[$key])) {
                return true;
            }
        }

        return false;
    }

    private function isBlank(mixed $value): bool
    {
        if (null === $value) {
            return true;
        }
        if (is_array($value)) {
            return [] === $value;
        }

        return '' === trim((string) $value);
    }
}
