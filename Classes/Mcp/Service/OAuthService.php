<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\TokenData;
use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Exception\InvalidGrantException;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Exception\InvalidTokenException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

class OAuthService
{
    private const MAX_ACTIVE_TOKENS_PER_USER = 20;

    private const CODE_LIFETIME = 600;

    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly PermissionService $permissionService,
        private readonly LoggerInterface $logger,
        private readonly int $tokenLifetimeDays = 30,
    ) {}

    /**
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
        $rawCode = bin2hex(random_bytes(32));
        $codeHash = hash('sha256', $rawCode);

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

    /**
     * @param string $resource the `resource` parameter sent on the token request (RFC 8707)
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

        if (null === $codeRecord) {
            throw new InvalidGrantException('Authorization code is invalid.');
        }

        if ((int) $codeRecord['expires_at'] < time()) {
            throw new InvalidGrantException('Authorization code has expired. Please start a new authorization flow.');
        }

        if (!hash_equals($codeRecord['client_id'], $clientId)) {
            throw new InvalidGrantException('Client ID does not match the authorization request.');
        }

        if ($codeRecord['redirect_uri'] !== $redirectUri) {
            throw new InvalidGrantException('Redirect URI does not match the authorization request.');
        }

        // resource binding (RFC 8707)
        $codeAudience = (string) ($codeRecord['audience'] ?? '');
        if ($codeAudience !== $resource) {
            throw new InvalidGrantException('resource parameter does not match the authorization request.');
        }

        // PKCE verification
        if (!$this->validatePkce($codeVerifier, $codeRecord['code_challenge'])) {
            throw new InvalidGrantException('PKCE verification failed. Please check your code_verifier.');
        }

        if (!$this->tokenRepository->markCodeUsed($codeHash)) {
            throw new InvalidGrantException('Authorization code has already been used.');
        }

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

    /**
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
        unset($result['refresh_token']);

        return $result;
    }

    /**
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

        // Audience binding (RFC 8707 / MCP 2025-11-25)
        $audience = (string) ($record['audience'] ?? '');
        if ('' === $audience) {
            throw new InvalidTokenException(
                'Token has no audience binding (legacy token issued before RFC 8707 enforcement). Please re-authorize.',
            );
        }
        if (!CanonicalResource::matches($audience)) {
            throw new InvalidTokenException('Token audience does not match this MCP server.');
        }

        $this->tokenRepository->updateLastUsed((int) $record['uid']);

        return new TokenData(
            beUserUid: (int) $record['be_user_uid'],
            scopes: array_values(array_filter(explode(' ', (string) $record['scopes']))),
            clientId: (string) $record['client_id'],
            tokenId: (string) $record['uid'],
            workspaceUid: isset($record['workspace_uid']) && (int) $record['workspace_uid'] > 0
                ? (int) $record['workspace_uid']
                : null,
            issuedVersion: (string) ($record['issued_version'] ?? ''),
        );
    }

    /**
     * @param string $resource optional `resource` parameter from the refresh request. When set,
     *                         must match the audience the original token was bound to (RFC 8707);
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
            $this->logger->warning('Refresh token not found — possible theft attempt', [
                'client_id' => $clientId,
            ]);

            throw new InvalidGrantException('Refresh token is invalid.');
        }

        if (0 !== (int) $existing['deleted']) {
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

        if (!hash_equals((string) $existing['client_id'], $clientId)) {
            throw new InvalidGrantException('Client ID does not match the token.');
        }

        $beUser = BackendUtility::getRecord('be_users', (int) $existing['be_user_uid']);
        if (null === $beUser || 0 !== (int) ($beUser['disable'] ?? 0) || 0 !== (int) ($beUser['deleted'] ?? 0)) {
            $this->tokenRepository->markDeleted((int) $existing['uid']);

            throw new InvalidGrantException('Your backend account has been deactivated. Please contact your administrator.');
        }

        // Audience binding (RFC 8707).
        $audience = (string) ($existing['audience'] ?? '');
        if ('' === $audience) {
            $this->tokenRepository->markDeleted((int) $existing['uid']);

            throw new InvalidGrantException(
                'Refresh token has no audience binding (legacy token). Please re-authorize.',
            );
        }
        if ('' !== $resource && $resource !== $audience) {
            throw new InvalidGrantException('resource parameter does not match the bound audience.');
        }

        $oldScopes = array_values(array_filter(explode(' ', (string) $existing['scopes'])));
        $validScopes = $this->filterScopesByPermissions($oldScopes, (int) $existing['be_user_uid']);

        $this->tokenRepository->markDeleted((int) $existing['uid']);

        return $this->createTokenPair(
            (int) $existing['be_user_uid'],
            $clientId,
            $validScopes,
            $audience,
        );
    }

    public function revokeToken(string $rawToken): void
    {
        $tokenHash = hash('sha256', $rawToken);
        $this->tokenRepository->markDeletedByHash($tokenHash);
    }

    public function validatePkce(string $codeVerifier, string $codeChallenge): bool
    {
        if ('' === $codeVerifier || '' === $codeChallenge) {
            return false;
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $computed);
    }

    /**
     * @param list<string> $requestedScopes
     *
     * @return list<string>
     */
    public function filterScopesByPermissions(array $requestedScopes, int $beUserUid): array
    {
        $availableScopes = $this->permissionService->getAvailableScopes();

        return array_values(array_intersect($requestedScopes, $availableScopes));
    }

    /**
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
        $activeCount = $this->tokenRepository->countActiveTokensForUser($beUserUid);
        if ($activeCount >= self::MAX_ACTIVE_TOKENS_PER_USER) {
            $this->tokenRepository->revokeOldestTokenForUser($beUserUid);
        }

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
            'issued_version' => ExtensionManagementUtility::getExtensionVersion('ai_suite_mcp'),
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
