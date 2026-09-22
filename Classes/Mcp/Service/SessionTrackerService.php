<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;

class SessionTrackerService
{
    private int $creditsUsedInSession = 0;
    private string $tokenId = '';
    private int $maxCreditsPerSession = 0;
    private bool $initialized = false;

    public function __construct(
        private readonly TokenRepository $tokenRepository,
    ) {}

    public function initializeFromToken(string $tokenId, int $maxCreditsPerSession = 0): void
    {
        $this->tokenId = $tokenId;
        $this->maxCreditsPerSession = $maxCreditsPerSession;
        $this->creditsUsedInSession = $this->tokenRepository->getSessionCreditsUsed((int) $tokenId);
        $this->initialized = true;
    }

    public function trackUsage(int $credits): void
    {
        if ('' !== $this->tokenId) {
            $this->creditsUsedInSession = $this->tokenRepository->incrementSessionCreditsUsed(
                (int) $this->tokenId,
                $credits,
            );
        } else {
            $this->creditsUsedInSession += $credits;
        }
    }

    public function isExhausted(): bool
    {
        return $this->maxCreditsPerSession > 0 && $this->creditsUsedInSession >= $this->maxCreditsPerSession;
    }

    public function exhaustedMessage(): string
    {
        return sprintf(
            'This access token has used %d of its %d-credit budget, so no further AI requests are sent with it. '
            .'A newly issued token starts with a fresh budget, and an administrator can raise the limit (mcpMaxCreditsPerSession).',
            $this->creditsUsedInSession,
            $this->maxCreditsPerSession,
        );
    }

    public function getUsed(): int
    {
        return $this->creditsUsedInSession;
    }

    public function getRemaining(): int
    {
        if ($this->maxCreditsPerSession <= 0) {
            return -1; // unlimited
        }

        return max(0, $this->maxCreditsPerSession - $this->creditsUsedInSession);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }
}
