<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\RateLimitPolicy;
use AutoDudes\AiSuiteMcp\Mcp\Exception\RateLimitExceededException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

class RateLimiterService
{
    public function __construct(
        #[Autowire(service: 'cache.hash')]
        private readonly FrontendInterface $cache,
        private readonly ClockInterface $clock,
        private readonly int $maxAttempts = RateLimitPolicy::DEFAULT_MAX_ATTEMPTS,
        private readonly int $windowSeconds = RateLimitPolicy::DEFAULT_WINDOW_SECONDS,
        private readonly int $lockoutSeconds = RateLimitPolicy::DEFAULT_LOCKOUT_SECONDS,
    ) {}

    /**
     * @throws RateLimitExceededException
     */
    public function assertNotLocked(string $identifier): void
    {
        $data = $this->cache->get($this->cacheKey($identifier));
        $remaining = (int) (\is_array($data) ? ($data['locked_until'] ?? 0) : 0) - $this->clock->now()->getTimestamp();
        if ($remaining > 0) {
            throw new RateLimitExceededException(
                sprintf('Too many requests. Please try again in %d seconds.', $remaining),
                $remaining,
            );
        }
    }

    /**
     * @throws RateLimitExceededException
     */
    public function checkAndIncrement(string $identifier, ?RateLimitPolicy $policy = null): void
    {
        $policy ??= new RateLimitPolicy($this->maxAttempts, $this->windowSeconds, $this->lockoutSeconds);
        if ($policy->isUnlimited()) {
            return;
        }

        $cacheKey = $this->cacheKey($identifier);
        $data = $this->cache->get($cacheKey);
        $now = $this->clock->now()->getTimestamp();

        if (false === $data) {
            $data = ['count' => 0, 'first_at' => $now, 'locked_until' => 0];
        }

        if ($data['locked_until'] > $now) {
            $remaining = $data['locked_until'] - $now;

            throw new RateLimitExceededException(
                sprintf('Too many requests. Please try again in %d seconds.', $remaining),
                $remaining,
            );
        }

        if ($now - $data['first_at'] > $policy->windowSeconds) {
            $data = ['count' => 0, 'first_at' => $now, 'locked_until' => 0];
        }

        ++$data['count'];

        if ($data['count'] > $policy->maxAttempts) {
            $data['locked_until'] = $now + $policy->lockoutSeconds;
            $this->cache->set($cacheKey, $data, [], $policy->lockoutSeconds);

            throw new RateLimitExceededException(
                sprintf('Rate limit exceeded. Locked for %d seconds.', $policy->lockoutSeconds),
                $policy->lockoutSeconds,
            );
        }

        $this->cache->set($cacheKey, $data, [], $policy->windowSeconds);
    }

    private function cacheKey(string $identifier): string
    {
        return 'aisuite_rl_'.substr(md5($identifier), 0, 32);
    }
}
