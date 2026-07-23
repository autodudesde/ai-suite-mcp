<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

/**
 * Decides which record fields are worth showing to the LLM. Drops housekeeping/
 * system columns (versioning, timestamps, sorting, …) and — when the caller opts
 * in — empty values, so a read returns editorial content instead of noise.
 *
 * The block-list is the parent extension's {@see TcaCompatibilityService::getHousekeepingFields()}
 * (single source of truth) plus a few localization/versioning columns that list
 * does not cover but which are equally meaningless in a read.
 */
class FieldCurationService
{
    /**
     * Localization/versioning plumbing not contained in the parent housekeeping
     * list but still pure noise for an LLM read.
     *
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
     * @param bool $includeEmpty  keep fields whose value is empty (default read behaviour:
     *                            true, so the "find records with empty fields" use-case works)
     * @param bool $includeSystem keep housekeeping/system fields (default: false)
     */
    public function shouldInclude(string $field, mixed $value, bool $includeEmpty, bool $includeSystem): bool
    {
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
