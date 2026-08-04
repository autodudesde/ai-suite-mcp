<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

final class RequestParamsNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(mixed $params): array
    {
        $normalized = self::normalize($params);

        return \is_array($normalized) ? $normalized : [];
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return $serialized === $value ? [] : self::normalize($serialized);
        }

        if ($value instanceof \stdClass) {
            return self::normalize(get_object_vars($value));
        }

        if (\is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
