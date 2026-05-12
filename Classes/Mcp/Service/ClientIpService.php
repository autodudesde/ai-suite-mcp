<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Resolve the real client IP for audit logging when the MCP server runs behind a reverse proxy.
 * Trusted proxies are configured via `mcpTrustedProxies` (comma-separated CIDRs) in the
 * extension configuration. With an empty allowlist, X-Forwarded-For is ignored and the
 * peer IP is returned as before.
 */
class ClientIpService implements SingletonInterface
{
    /**
     * @var list<string>
     */
    private readonly array $trustedProxies;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $extConf = $extensionConfiguration->get('ai_suite_mcp');
        $raw = (string) ($extConf['mcpTrustedProxies'] ?? '');
        $this->trustedProxies = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $entry): bool => '' !== $entry,
        ));
    }

    /**
     * Return the best-effort real client IP for the given request.
     */
    public function resolve(ServerRequestInterface $request): string
    {
        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        if ('' === $remote || [] === $this->trustedProxies) {
            return $remote;
        }

        if (!IpUtils::checkIp($remote, $this->trustedProxies)) {
            // Direct peer is NOT in the trusted-proxy list — its X-Forwarded-For
            // header is untrusted (potentially spoofed). Fall back to peer IP.
            return $remote;
        }

        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ('' === $xff) {
            return $remote;
        }

        // Walk right to left through the forwarded chain. The right-most entry was
        // added by the immediate (trusted) proxy; entries further left were added
        // by upstream proxies and are themselves trustworthy if they appear in our
        // trusted list. The first non-trusted IP is the real client.
        $chain = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($chain as $candidate) {
            if ('' === $candidate) {
                continue;
            }
            if (!IpUtils::checkIp($candidate, $this->trustedProxies)) {
                return $candidate;
            }
        }

        // Entire chain was trusted proxies — return the left-most entry as the
        // best-guess client (it is the original ingress IP into the trusted zone).
        $leftMost = end($chain);

        return false !== $leftMost && '' !== $leftMost ? $leftMost : $remote;
    }
}
