<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

final class BatchIndexRemapper
{
    /**
     * @param list<int> $insertCounts
     *
     * @return array<int, int>
     */
    public static function buildIndexMap(array $insertCounts): array
    {
        $map = [];
        $shifted = 0;
        foreach ($insertCounts as $originalIndex => $extra) {
            $map[$originalIndex] = $shifted;
            $shifted += 1 + $extra;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int, int>      $indexMap
     *
     * @return array<string, mixed>
     */
    public static function remap(array $fields, array $indexMap): array
    {
        foreach ($fields as $field => $value) {
            if (is_string($value) && 1 === preg_match('/^\$ref:(\d+)$/', $value, $matches)) {
                $original = (int) $matches[1];
                if (isset($indexMap[$original])) {
                    $fields[$field] = '$ref:'.$indexMap[$original];
                }
            }
        }

        return $fields;
    }
}
