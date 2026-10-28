<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use AutoDudes\AiSuiteMcp\Domain\Repository\OAuthClientRepository;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\RedirectUriPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

class RegistrationEndpoint
{
    private const MAX_REDIRECT_URIS = 10;
    private const MAX_URI_LENGTH = 2048;
    private const MAX_NAME_LENGTH = 100;

    public function __construct(
        private readonly OAuthClientRepository $clientRepository,
        private readonly RedirectUriPolicy $redirectUriPolicy,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ('POST' !== $request->getMethod()) {
            return new JsonResponse(['error' => 'method_not_allowed'], 405);
        }

        $body = json_decode((string) $request->getBody(), true);
        $body = \is_array($body) ? $body : [];

        $clientName = $this->clientName($body['client_name'] ?? null);
        $redirectUris = $body['redirect_uris'] ?? null;
        if (!\is_array($redirectUris) || [] === $redirectUris || \count($redirectUris) > self::MAX_REDIRECT_URIS) {
            return $this->error('invalid_redirect_uri', sprintf('redirect_uris must list between 1 and %d URIs.', self::MAX_REDIRECT_URIS));
        }

        $accepted = [];
        foreach ($redirectUris as $uri) {
            if (!\is_string($uri) || '' === $uri || \strlen($uri) > self::MAX_URI_LENGTH || !$this->redirectUriPolicy->isAllowed($uri)) {
                $this->logger->warning('MCP OAuth: registration refused a redirect URI', [
                    'host' => \is_string($uri) ? $this->redirectUriPolicy->host($uri) : '',
                ]);

                return $this->error(
                    'invalid_redirect_uri',
                    'Every redirect URI must be https (http only for localhost) and allowed by this server (mcpAllowedRedirectUris).',
                );
            }
            $accepted[] = $uri;
        }

        $applicationType = 'native' === ($body['application_type'] ?? '') ? 'native' : 'web';
        $clientId = 'mcp-'.bin2hex(random_bytes(16));
        $this->clientRepository->register($clientId, $clientName, array_values(array_unique($accepted)), time());

        return new JsonResponse([
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => array_values(array_unique($accepted)),
            'application_type' => $applicationType,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
        ], 201);
    }

    private function clientName(mixed $value): string
    {
        $name = \is_string($value) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)) : '';
        if ('' === $name) {
            return 'MCP Client';
        }

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    private function error(string $error, string $description): ResponseInterface
    {
        return new JsonResponse(['error' => $error, 'error_description' => $description], 400);
    }
}
