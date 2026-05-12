<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Holds the context of the current MCP session: backend user + OAuth scopes.
 */
class McpUserContext
{
    private int $beUserUid = 0;

    /** @var list<string> */
    private array $scopes = [];

    private string $clientId = '';

    private string $tokenId = '';

    private bool $initialized = false;

    private ?ServerRequestInterface $serverRequest = null;

    /**
     * Initialize the context. May only be called once per request.
     *
     * @param list<string> $scopes
     *
     * @throws \LogicException If called more than once
     */
    public function initialize(int $beUserUid, array $scopes, string $clientId, string $tokenId): void
    {
        if ($this->initialized) {
            throw new \LogicException(
                'McpUserContext has already been initialized. It may only be initialized once per request.',
            );
        }

        $this->beUserUid = $beUserUid;
        $this->scopes = $scopes;
        $this->clientId = $clientId;
        $this->tokenId = $tokenId;
        $this->initialized = true;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getBeUserUid(): int
    {
        return $this->beUserUid;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function setServerRequest(ServerRequestInterface $request): void
    {
        $this->serverRequest = $request;
    }

    public function getServerRequest(): ?ServerRequestInterface
    {
        return $this->serverRequest;
    }
}
