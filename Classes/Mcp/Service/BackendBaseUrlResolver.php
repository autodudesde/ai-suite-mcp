<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

class BackendBaseUrlResolver implements SingletonInterface
{
    private ?string $resolved = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SiteFinder $siteFinder,
        private readonly McpUserContext $userContext,
        private readonly LoggerInterface $logger,
    ) {}

    public function makeAbsolute(string $url): ?string
    {
        if ('' === $url) {
            return null;
        }
        if (1 === preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $base = $this->getBaseUrl();
        if (null === $base) {
            $this->logger->warning('MCP: no backend base URL could be resolved, dropping link', ['url' => $url]);

            return null;
        }

        return $base.'/'.ltrim($url, '/');
    }

    public function getBaseUrl(): ?string
    {
        if (null !== $this->resolved) {
            return '' === $this->resolved ? null : $this->resolved;
        }

        $base = $this->fromConfiguration() ?? $this->fromRequest() ?? $this->fromSites();
        $this->resolved = $base ?? '';

        return $base;
    }

    private function fromConfiguration(): ?string
    {
        try {
            $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return null;
        }

        return $this->normalize((string) ($extConf['mcpBackendBaseUrl'] ?? ''));
    }

    private function fromRequest(): ?string
    {
        $request = $this->userContext->getServerRequest() ?? ($GLOBALS['TYPO3_REQUEST'] ?? null);
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $normalizedParams = $request->getAttribute('normalizedParams');

        return $normalizedParams instanceof NormalizedParams
            ? $this->normalize($normalizedParams->getRequestHost())
            : $this->normalize((string) $request->getUri());
    }

    private function fromSites(): ?string
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $base = $this->normalize((string) $site->getBase());
            if (null !== $base) {
                return $base;
            }
        }

        return null;
    }

    private function normalize(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ('' === $candidate || 1 !== preg_match('#^https?://#i', $candidate)) {
            return null;
        }
        $parts = parse_url($candidate);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
    }
}
