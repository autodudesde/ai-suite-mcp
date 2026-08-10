<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

class FieldCurationService
{
    /**
     * @var list<string>
     */
    private const EXTRA_HOUSEKEEPING = [
        'l10n_source',
        'l18n_parent',
        'l18n_diffsource',
        'l10n_state',
        't3ver_timestamp',
    ];

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    public function isHousekeeping(string $field): bool
    {
        return \in_array($field, $this->tcaCompatibilityService->getHousekeepingFields(), true)
            || \in_array($field, self::EXTRA_HOUSEKEEPING, true);
    }

    /**
     * @param null|list<string> $allow
     */
    public function shouldInclude(string $field, mixed $value, bool $includeEmpty, bool $includeSystem, ?array $allow = null): bool
    {
        if (null !== $allow) {
            return in_array($field, $allow, true);
        }

        if (!$includeSystem && $this->isHousekeeping($field)) {
            return false;
        }
        if (!$includeEmpty && $this->isEmpty($value)) {
            return false;
        }

        return true;
    }

    public function isEmpty(mixed $value): bool
    {
        if (null === $value) {
            return true;
        }
        if (\is_array($value)) {
            return [] === $value;
        }

        return '' === trim((string) $value);
    }
}
