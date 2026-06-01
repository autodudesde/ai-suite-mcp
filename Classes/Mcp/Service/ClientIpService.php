<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

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

    public function resolve(ServerRequestInterface $request): string
    {
        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        if ('' === $remote || [] === $this->trustedProxies) {
            return $remote;
        }

        if (!IpUtils::checkIp($remote, $this->trustedProxies)) {
            return $remote;
        }

        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ('' === $xff) {
            return $remote;
        }

        $chain = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($chain as $candidate) {
            if ('' === $candidate) {
                continue;
            }
            if (!IpUtils::checkIp($candidate, $this->trustedProxies)) {
                return $candidate;
            }
        }

        $leftMost = end($chain);

        return false !== $leftMost && '' !== $leftMost ? $leftMost : $remote;
    }
}
