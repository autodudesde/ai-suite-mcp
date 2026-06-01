<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

class RateLimiterService
{
    private const DEFAULT_MAX_ATTEMPTS = 100;
    private const DEFAULT_WINDOW_SECONDS = 60;
    private const DEFAULT_LOCKOUT_SECONDS = 60;

    public function __construct(
        #[Autowire(service: 'cache.hash')]
        private readonly FrontendInterface $cache,
        private readonly ClockInterface $clock,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
        private readonly int $lockoutSeconds = self::DEFAULT_LOCKOUT_SECONDS,
    ) {}

    /**
     * @throws \RuntimeException If rate limit is exceeded
     */
    public function checkAndIncrement(string $identifier): void
    {
        $cacheKey = 'aisuite_rl_'.substr(md5($identifier), 0, 32);
        $data = $this->cache->get($cacheKey);
        $now = $this->clock->now()->getTimestamp();

        if (false === $data) {
            $data = ['count' => 0, 'first_at' => $now, 'locked_until' => 0];
        }

        if ($data['locked_until'] > $now) {
            $remaining = $data['locked_until'] - $now;

            throw new \RuntimeException(
                sprintf('Too many requests. Please try again in %d seconds.', $remaining),
            );
        }

        if ($now - $data['first_at'] > $this->windowSeconds) {
            $data = ['count' => 0, 'first_at' => $now, 'locked_until' => 0];
        }

        ++$data['count'];

        if ($data['count'] > $this->maxAttempts) {
            $data['locked_until'] = $now + $this->lockoutSeconds;
            $this->cache->set($cacheKey, $data, [], $this->lockoutSeconds);

            throw new \RuntimeException(
                sprintf('Rate limit exceeded. Locked for %d seconds.', $this->lockoutSeconds),
            );
        }

        $this->cache->set($cacheKey, $data, [], $this->windowSeconds);
    }

    public static function forAuthEndpoints(FrontendInterface $cache, ClockInterface $clock): self
    {
        return new self($cache, $clock, maxAttempts: 10, windowSeconds: 300, lockoutSeconds: 900);
    }

    public static function forMcpRequests(FrontendInterface $cache, ClockInterface $clock): self
    {
        return new self($cache, $clock, maxAttempts: 100, windowSeconds: 60, lockoutSeconds: 60);
    }
}
