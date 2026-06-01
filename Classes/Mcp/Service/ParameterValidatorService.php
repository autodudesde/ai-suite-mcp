<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;

class ParameterValidatorService
{
    /**
     * @param array<string, mixed> $params Raw parameters from the MCP client
     * @param array<string, mixed> $schema The tool's getSchema() result
     *
     * @return array<string, mixed> Validated and sanitized parameters
     *
     * @throws InvalidParameterException
     */
    public function validate(array $params, array $schema): array
    {
        $properties = $schema['properties'] ?? [];

        /** @var list<string> $knownKeys */
        $knownKeys = $properties instanceof \stdClass ? [] : array_keys($properties);

        // Lenient parameter name matching: map snake_case/kebab-case variants to camelCase
        $params = $this->normalizeParameterNames($params, $knownKeys);

        // Required fields
        foreach ($schema['required'] ?? [] as $required) {
            if (!array_key_exists($required, $params)) {
                throw new InvalidParameterException(
                    sprintf('Missing required parameter: %s', $required),
                );
            }
        }

        // Type validation and casting
        foreach ($params as $key => $value) {
            $propSchema = $schema['properties'][$key] ?? null;
            if (null === $propSchema) {
                unset($params[$key]);

                continue;
            }

            $params[$key] = match ($propSchema['type'] ?? null) {
                'integer' => $this->validateInteger($key, $value, $propSchema),
                'boolean' => (bool) $value,
                'string' => $this->validateString($key, $value, $propSchema),
                'array' => $this->validateArray($key, $value, $propSchema),
                default => $value,
            };
        }

        // Defaults for missing optional parameters
        foreach ($schema['properties'] ?? [] as $key => $propSchema) {
            if (!array_key_exists($key, $params) && isset($propSchema['default'])) {
                $params[$key] = $propSchema['default'];
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params    Raw parameters from the client
     * @param list<string>         $knownKeys Schema property names (camelCase)
     *
     * @return array<string, mixed> Parameters with normalized keys
     */
    private function normalizeParameterNames(array $params, array $knownKeys): array
    {
        $lookup = [];
        foreach ($knownKeys as $canonical) {
            $lookup[strtolower($canonical)] = $canonical;
        }

        $normalized = [];
        foreach ($params as $key => $value) {
            if (isset($lookup[strtolower($key)])) {
                $normalized[$lookup[strtolower($key)]] = $value;

                continue;
            }

            $stripped = strtolower(str_replace(['-', '_'], '', $key));
            if (isset($lookup[$stripped])) {
                $normalized[$lookup[$stripped]] = $value;

                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validateInteger(string $key, mixed $value, array $schema): int
    {
        if (!is_numeric($value)) {
            throw new InvalidParameterException(
                sprintf('%s must be an integer, got: %s', $key, get_debug_type($value)),
            );
        }

        $value = (int) $value;

        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            throw new InvalidParameterException(
                sprintf('%s must be >= %d, got: %d', $key, $schema['minimum'], $value),
            );
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            throw new InvalidParameterException(
                sprintf('%s must be <= %d, got: %d', $key, $schema['maximum'], $value),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validateString(string $key, mixed $value, array $schema): string
    {
        $value = (string) $value;

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new InvalidParameterException(
                sprintf('%s must be one of: %s', $key, implode(', ', $schema['enum'])),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function validateArray(string $key, mixed $value, array $schema): array
    {
        if (!is_array($value)) {
            throw new InvalidParameterException(
                sprintf('%s must be an array', $key),
            );
        }

        if (isset($schema['items']['enum'])) {
            foreach ($value as $item) {
                if (!in_array($item, $schema['items']['enum'], true)) {
                    throw new InvalidParameterException(
                        sprintf('%s items must be one of: %s', $key, implode(', ', $schema['items']['enum'])),
                    );
                }
            }
        }

        return $value;
    }
}
