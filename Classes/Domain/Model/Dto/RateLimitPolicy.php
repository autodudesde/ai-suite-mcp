<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

final class RateLimitPolicy
{
    public const DEFAULT_MAX_ATTEMPTS = 100;
    public const DEFAULT_WINDOW_SECONDS = 60;
    public const DEFAULT_LOCKOUT_SECONDS = 60;

    public function __construct(
        public readonly int $maxAttempts,
        public readonly int $windowSeconds,
        public readonly int $lockoutSeconds,
    ) {}

    /**
     * @param array<string, mixed> $extConf
     */
    public static function forBearerToken(array $extConf): self
    {
        return new self(
            max(0, (int) ($extConf['mcpRateLimitRequests'] ?? self::DEFAULT_MAX_ATTEMPTS)),
            max(1, (int) ($extConf['mcpRateLimitWindowSeconds'] ?? self::DEFAULT_WINDOW_SECONDS)),
            max(1, (int) ($extConf['mcpRateLimitLockoutSeconds'] ?? self::DEFAULT_LOCKOUT_SECONDS)),
        );
    }

    public static function oauthEndpoint(): self
    {
        return new self(self::DEFAULT_MAX_ATTEMPTS, self::DEFAULT_WINDOW_SECONDS, self::DEFAULT_LOCKOUT_SECONDS);
    }

    public static function mfaVerify(): self
    {
        return new self(10, 300, 300);
    }

    public static function oauthLogin(): self
    {
        return new self(10, 900, 900);
    }

    public static function chatTurn(): self
    {
        return new self(self::DEFAULT_MAX_ATTEMPTS, self::DEFAULT_WINDOW_SECONDS, self::DEFAULT_LOCKOUT_SECONDS);
    }

    public function isUnlimited(): bool
    {
        return $this->maxAttempts <= 0;
    }
}
