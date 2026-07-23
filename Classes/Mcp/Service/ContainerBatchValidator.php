<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;

/**
 * Rejects a write batch that creates a container element without any children in it.
 *
 * A b13 container holds its children through `tx_container_parent` + `colPos`, and writeRecords can
 * create both in one call: the container is a record, each child references it with `$ref:<index>`.
 * The schema description says so. Measured, gpt-5.4-nano still wrote two containers and no children,
 * leaving the editor with two empty boxes, and only wired the children up after being told.
 *
 * Prose does not fix that (a container written empty is a valid record, so nothing pushes back), so
 * this pushes back: the batch is refused before anything is written, and the message carries the
 * `$ref` syntax and the container's real colPos slots, which is everything the model needs to retry
 * in the same turn.
 *
 * Deliberately escapable with `allowEmptyContainer` — an editor may legitimately want an empty grid.
 */
class ContainerBatchValidator
{
    public function __construct(
        private readonly TcaLabelService $tcaLabel,
    ) {}

    /**
     * @param array<int, mixed> $records the flat batch, after nested children were expanded
     *
     * @throws InvalidParameterException
     */
    public function assertContainersHaveChildren(array $records, bool $allowEmptyContainer = false): void
    {
        if ($allowEmptyContainer) {
            return;
        }

        $registry = $this->tcaLabel->getContainerRegistry();
        if (null === $registry) {
            // b13/container is not installed: there are no containers to get wrong.
            return;
        }

        $containers = [];
        $referenced = [];

        foreach (array_values($records) as $index => $record) {
            if (!is_array($record)) {
                continue;
            }
            $fields = $record['fields'] ?? [];
            if (!is_array($fields)) {
                continue;
            }

            // Only a container being CREATED here can be left empty by this batch. Updating an
            // existing container says nothing about the children it already has.
            $isCreate = !isset($record['uid']);
            $cType = is_scalar($fields['CType'] ?? null) ? (string) $fields['CType'] : '';

            if ($isCreate && 'tt_content' === (string) ($record['table'] ?? '') && '' !== $cType) {
                try {
                    if ($registry->isContainerElement($cType)) {
                        $containers[$index] = $cType;
                    }
                } catch (\Throwable) {
                    // A registry that cannot answer must not break the write.
                    continue;
                }
            }

            $parent = $fields['tx_container_parent'] ?? null;
            if (is_string($parent) && 1 === preg_match('/^\$ref:(\d+)$/', $parent, $matches)) {
                $referenced[(int) $matches[1]] = true;
            }
        }

        foreach ($containers as $index => $cType) {
            if (isset($referenced[$index])) {
                continue;
            }

            throw new InvalidParameterException($this->emptyContainerMessage($index, $cType));
        }
    }

    private function emptyContainerMessage(int $index, string $cType): string
    {
        $message = sprintf(
            'Record %d creates the container `%s` but no record in this batch goes into it, so it would render as an empty box. '
                .'Nothing was written. Put the children into this same call: each child sets `tx_container_parent: "$ref:%d"` and a `colPos` from this container\'s grid.',
            $index,
            $cType,
            $index,
        );

        $slots = $this->describeSlots($cType);
        if ('' !== $slots) {
            $message .= sprintf(' Its slots are: %s.', $slots);
        }

        return $message.' Pass `allowEmptyContainer: true` if you really want the container to stay empty.';
    }

    private function describeSlots(string $cType): string
    {
        $registry = $this->tcaLabel->getContainerRegistry();
        if (null === $registry) {
            return '';
        }

        try {
            $slots = [];
            foreach ($registry->getAvailableColumns($cType) as $column) {
                $label = $this->tcaLabel->resolveLabel((string) ($column['name'] ?? ''));
                $slots[] = sprintf('colPos %d (%s)', (int) ($column['colPos'] ?? 0), '' !== $label ? $label : 'unnamed');
            }

            return implode(', ', $slots);
        } catch (\Throwable) {
            return '';
        }
    }
}
