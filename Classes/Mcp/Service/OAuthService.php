<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\TokenData;
use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use AutoDudes\AiSuiteMcp\Mcp\McpPermissionService;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Exception\InvalidGrantException;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Exception\InvalidTokenException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * OAuth 2.1 Authorization Server implementation.
 */
class OAuthService
{
    /**
     * Maximum active tokens per user. Oldest is revoked when limit is reached.
     */
    private const MAX_ACTIVE_TOKENS_PER_USER = 20;

    /**
     * Authorization code lifetime in seconds (10 minutes).
     */
    private const CODE_LIFETIME = 600;

    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly McpPermissionService $permissionService,
        private readonly LoggerInterface $logger,
        private readonly int $tokenLifetimeDays = 30,
    ) {}

    // ── Authorization Codes ──

    /**
     * Create an authorization code for the OAuth flow.
     *
     * @param list<string> $requestedScopes
     * @param string       $audience        canonical resource URI the resulting token will be bound to (RFC 8707)
     *
     * @return string The plaintext code (shown once, then forgotten)
     */
    public function createAuthorizationCode(
        int $beUserUid,
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        array $requestedScopes,
        string $audience,
        ?int $workspaceUid = null,
    ): string {
        // Generate cryptographically secure code
        $rawCode = bin2hex(random_bytes(32));
        $codeHash = hash('sha256', $rawCode);

        // Downscope: filter requested scopes by user's actual permissions (S13)
        $grantedScopes = $this->filterScopesByPermissions($requestedScopes, $beUserUid);

        $this->tokenRepository->createCode([
            'code' => $codeHash,
            'be_user_uid' => $beUserUid,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scopes' => implode(' ', $grantedScopes),
            'audience' => $audience,
            'workspace_uid' => $workspaceUid ?? 0,
            'expires_at' => time() + self::CODE_LIFETIME,
            'used' => 0,
        ]);

        return $rawCode;
    }

    // ── Code → Token Exchange ──

    /**
     * Exchange an authorization code for an access token.
     *
     * @param string $resource the `resource` parameter sent on the token request (RFC 8707).
     *                         Must match the audience that was bound to the code at /authorize time —
     *                         otherwise an attacker who intercepted a code could redeem it for a
     *                         token bound to a different MCP server.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     *
     * @throws InvalidGrantException
     */
    public function exchangeCodeForToken(
        string $rawCode,
        string $codeVerifier,
        string $clientId,
        string $redirectUri,
        string $resource,
    ): array {
        $codeHash = hash('sha256', $rawCode);
        $codeRecord = $this->tokenRepository->findCodeByHash($codeHash);

        // Code exists?
        if (null === $codeRecord) {
            throw new InvalidGrantException('Authorization code is invalid.');
        }

        // Code expired?
        if ((int) $codeRecord['expires_at'] < time()) {
            throw new InvalidGrantException('Authorization code has expired. Please start a new authorization flow.');
        }

        // Client ID matches?
        if (!hash_equals($codeRecord['client_id'], $clientId)) {
            throw new InvalidGrantException('Client ID does not match the authorization request.');
        }

        // redirect_uri binding (S10 — exact match)
        if ($codeRecord['redirect_uri'] !== $redirectUri) {
            throw new InvalidGrantException('Redirect URI does not match the authorization request.');
        }

        // resource binding (RFC 8707) — must match the audience captured at /authorize time
        $codeAudience = (string) ($codeRecord['audience'] ?? '');
        if ($codeAudience !== $resource) {
            throw new InvalidGrantException('resource parameter does not match the authorization request.');
        }

        // PKCE verification (S20 — S256 only)
        if (!$this->validatePkce($codeVerifier, $codeRecord['code_challenge'])) {
            throw new InvalidGrantException('PKCE verification failed. Please check your code_verifier.');
        }

        // Atomic single-use: mark as used (race condition safe)
        if (!$this->tokenRepository->markCodeUsed($codeHash)) {
            // Another request already used this code
            throw new InvalidGrantException('Authorization code has already been used.');
        }

        // Create token pair — propagate workspace_uid from code so the consent-bound workspace
        // is preserved through the token-exchange step (otherwise the token would default to 0).
        $scopes = array_values(array_filter(explode(' ', (string) $codeRecord['scopes'])));
        $codeWorkspaceUid = isset($codeRecord['workspace_uid']) && (int) $codeRecord['workspace_uid'] > 0
            ? (int) $codeRecord['workspace_uid']
            : null;

        return $this->createTokenPair(
            (int) $codeRecord['be_user_uid'],
            $clientId,
            $scopes,
            $codeAudience,
            $codeWorkspaceUid,
        );
    }

    // ── Token Creation ──

    /**
     * Create an access token directly (for manual token generation in backend / CLI).
     *
     * Audience defaults to the canonical resource URI of this server, since CLI/backend-issued
     * PAT-style tokens are always intended for use against this very installation.
     *
     * @param list<string> $scopes
     *
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function createAccessToken(
        int $beUserUid,
        string $clientId,
        array $scopes,
        ?int $workspaceUid = null,
        string $audience = '',
    ): array {
        $effectiveAudience = '' !== $audience ? $audience : CanonicalResource::get();
        $result = $this->createTokenPair($beUserUid, $clientId, $scopes, $effectiveAudience, $workspaceUid);
        // Remove refresh_token for direct creation (not needed for PAT-style tokens)
        unset($result['refresh_token']);

        return $result;
    }

    // ── Token Validation ──

    /**
     * Validate a bearer token and return the associated data.
     *
     * @throws InvalidTokenException
     */
    public function validateToken(string $rawToken): TokenData
    {
        $tokenHash = hash('sha256', $rawToken);
        $record = $this->tokenRepository->findByTokenHash($tokenHash);

        if (null === $record || 0 !== (int) $record['deleted']) {
            throw new InvalidTokenException('Token is invalid or has been revoked.');
        }

        if ((int) $record['expires_at'] < time()) {
            throw new InvalidTokenException('Token has expired. Please obtain a new token.');
        }

        // Audience binding (RFC 8707 / MCP 2025-11-25). Tokens without audience are legacy
        // (issued before this enforcement) and must be rejected — re-authorization is the
        // expected migration path. Tokens for a different audience are an attempted token
        // confusion / passthrough attack.
        $audience = (string) ($record['audience'] ?? '');
        if ('' === $audience) {
            throw new InvalidTokenException(
                'Token has no audience binding (legacy token issued before RFC 8707 enforcement). Please re-authorize.',
            );
        }
        if (!CanonicalResource::matches($audience)) {
            throw new InvalidTokenException('Token audience does not match this MCP server.');
        }

        // Update last used timestamp
        $this->tokenRepository->updateLastUsed((int) $record['uid']);

        return new TokenData(
            beUserUid: (int) $record['be_user_uid'],
            scopes: array_values(array_filter(explode(' ', (string) $record['scopes']))),
            clientId: (string) $record['client_id'],
            tokenId: (string) $record['uid'],
            workspaceUid: isset($record['workspace_uid']) && (int) $record['workspace_uid'] > 0
                ? (int) $record['workspace_uid']
                : null,
        );
    }

    // ── Token Refresh ──

    /**
     * Refresh an access token using a refresh token.
     * Implements rotation + theft detection.
     *
     * @param string $resource optional `resource` parameter from the refresh request. When set,
     *                         must match the audience the original token was bound to (RFC 8707);
     *                         empty means "use the audience from the existing token" (default).
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     *
     * @throws InvalidGrantException
     */
    public function refreshAccessToken(string $rawRefreshToken, string $clientId, string $resource = ''): array
    {
        $refreshHash = hash('sha256', $rawRefreshToken);
        $existing = $this->tokenRepository->findByRefreshTokenHash($refreshHash);

        if (null === $existing) {
            // Theft detection: if the refresh token was already rotated,
            // this means someone is reusing a stolen token.
            // We can't distinguish "token not found" from "token was rotated"
            // at this point. Log as warning.
            $this->logger->warning('Refresh token not found — possible theft attempt', [
                'client_id' => $clientId,
            ]);

            throw new InvalidGrantException('Refresh token is invalid.');
        }

        if (0 !== (int) $existing['deleted']) {
            // THEFT DETECTION (S24): A deleted/rotated refresh token was reused.
            // This means the old token was stolen. Revoke ALL tokens for this user+client.
            $revokedCount = $this->tokenRepository->revokeAllTokensForUserAndClient(
                (int) $existing['be_user_uid'],
                (string) $existing['client_id'],
            );

            $this->logger->critical('SECURITY: Refresh token reuse detected — possible token theft', [
                'be_user_uid' => $existing['be_user_uid'],
                'client_id' => $existing['client_id'],
                'revoked_tokens' => $revokedCount,
            ]);

            throw new InvalidGrantException(
                'Security alert: This refresh token has already been used. '
                .'All sessions for this client have been revoked. Please re-authorize.',
            );
        }

        // Client ID must match
        if (!hash_equals((string) $existing['client_id'], $clientId)) {
            throw new InvalidGrantException('Client ID does not match the token.');
        }

        // Backend user status live check
        $beUser = BackendUtility::getRecord('be_users', (int) $existing['be_user_uid']);
        if (null === $beUser || 0 !== (int) ($beUser['disable'] ?? 0) || 0 !== (int) ($beUser['deleted'] ?? 0)) {
            $this->tokenRepository->markDeleted((int) $existing['uid']);

            throw new InvalidGrantException('Your backend account has been deactivated. Please contact your administrator.');
        }

        // Audience binding (RFC 8707). The new token inherits the audience of the old token —
        // refresh is not allowed to switch audiences. If the client supplied a `resource`
        // parameter, it must agree with the bound audience.
        $audience = (string) ($existing['audience'] ?? '');
        if ('' === $audience) {
            // Legacy refresh token without audience — force re-authorization.
            $this->tokenRepository->markDeleted((int) $existing['uid']);

            throw new InvalidGrantException(
                'Refresh token has no audience binding (legacy token). Please re-authorize.',
            );
        }
        if ('' !== $resource && $resource !== $audience) {
            throw new InvalidGrantException('resource parameter does not match the bound audience.');
        }

        // Re-validate scopes against current permissions
        $oldScopes = array_values(array_filter(explode(' ', (string) $existing['scopes'])));
        $validScopes = $this->filterScopesByPermissions($oldScopes, (int) $existing['be_user_uid']);

        // Invalidate old token (rotation)
        $this->tokenRepository->markDeleted((int) $existing['uid']);

        // Create new token pair with potentially reduced scopes; audience inherited from old token.
        return $this->createTokenPair(
            (int) $existing['be_user_uid'],
            $clientId,
            $validScopes,
            $audience,
        );
    }

    // ── Token Revocation ──

    /**
     * Revoke a token by its plaintext value (soft-delete).
     */
    public function revokeToken(string $rawToken): void
    {
        $tokenHash = hash('sha256', $rawToken);
        $this->tokenRepository->markDeletedByHash($tokenHash);
    }

    // ── PKCE ──

    /**
     * Validate PKCE challenge using S256 method only.
     * Plain method is rejected per OAuth 2.1 / MCP spec.
     */
    public function validatePkce(string $codeVerifier, string $codeChallenge): bool
    {
        if ('' === $codeVerifier || '' === $codeChallenge) {
            return false;
        }

        // S256: BASE64URL(SHA256(code_verifier)) === code_challenge
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $computed);
    }

    // ── Scope Downscoping ──

    /**
     * Filter requested scopes to only those the user has TYPO3 permissions for..
     *
     * @param list<string> $requestedScopes
     *
     * @return list<string>
     */
    public function filterScopesByPermissions(array $requestedScopes, int $beUserUid): array
    {
        $availableScopes = $this->permissionService->getAvailableScopes();

        return array_values(array_intersect($requestedScopes, $availableScopes));
    }

    // ── Internal ──

    /**
     * Create an access + refresh token pair.
     *
     * @param list<string> $scopes
     * @param string       $audience canonical resource URI the token is bound to (RFC 8707)
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     */
    private function createTokenPair(
        int $beUserUid,
        string $clientId,
        array $scopes,
        string $audience,
        ?int $workspaceUid = null,
    ): array {
        // Enforce max active tokens per user
        $activeCount = $this->tokenRepository->countActiveTokensForUser($beUserUid);
        if ($activeCount >= self::MAX_ACTIVE_TOKENS_PER_USER) {
            $this->tokenRepository->revokeOldestTokenForUser($beUserUid);
        }

        // Generate cryptographically secure tokens
        $rawAccessToken = bin2hex(random_bytes(32));
        $rawRefreshToken = bin2hex(random_bytes(32));

        $expiresIn = $this->tokenLifetimeDays * 86400;

        $this->tokenRepository->createToken([
            'token' => hash('sha256', $rawAccessToken),
            'refresh_token' => hash('sha256', $rawRefreshToken),
            'be_user_uid' => $beUserUid,
            'client_id' => $clientId,
            'scopes' => implode(' ', $scopes),
            'audience' => $audience,
            'workspace_uid' => $workspaceUid ?? 0,
            'created_at' => time(),
            'expires_at' => time() + $expiresIn,
            'session_credits_used' => 0,
            'deleted' => 0,
        ]);

        return [
            'access_token' => $rawAccessToken,
            'refresh_token' => $rawRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'scope' => implode(' ', $scopes),
        ];
    }
}
