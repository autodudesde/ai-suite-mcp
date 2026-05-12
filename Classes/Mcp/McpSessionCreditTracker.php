<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;

class McpSessionCreditTracker
{
    private int $creditsUsedInSession = 0;
    private string $tokenId = '';
    private int $maxCreditsPerSession = 0;
    private bool $initialized = false;

    public function __construct(
        private readonly TokenRepository $tokenRepository,
    ) {}

    /**
     * Load session credit state from DB token record.
     */
    public function initializeFromToken(string $tokenId, int $maxCreditsPerSession = 0): void
    {
        $this->tokenId = $tokenId;
        $this->maxCreditsPerSession = $maxCreditsPerSession;
        $this->creditsUsedInSession = $this->tokenRepository->getSessionCreditsUsed((int) $tokenId);
        $this->initialized = true;
    }

    /**
     * @throws \RuntimeException If budget exceeded
     */
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

        if ($this->maxCreditsPerSession > 0 && $this->creditsUsedInSession >= $this->maxCreditsPerSession) {
            throw new \RuntimeException(sprintf(
                "You've used %d credits in this session — great productivity! "
                .'Your session budget of %d credits has been reached. '
                .'Start a new session or contact your administrator for a higher budget.',
                $this->creditsUsedInSession,
                $this->maxCreditsPerSession,
            ));
        }
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
