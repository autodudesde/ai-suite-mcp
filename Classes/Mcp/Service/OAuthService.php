<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\TokenData;
use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Exception\InvalidGrantException;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Exception\InvalidTokenException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

class OAuthService
{
    private const MAX_ACTIVE_TOKENS_PER_USER = 20;

    private const CODE_LIFETIME = 600;

    private const REFRESH_GRACE_SECONDS = 30;

    private const DEFAULT_TOKEN_LIFETIME_DAYS = 30;

    private const DEFAULT_REFRESH_MAX_LIFETIME_DAYS = 90;

    private const DEFAULT_ACCESS_TOKEN_LIFETIME_MINUTES = 60;

    private const DEFAULT_REFRESH_IDLE_DAYS = 30;

    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly PermissionService $permissionService,
        private readonly LoggerInterface $logger,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly TokenAuthenticatedBackendUserService $tokenAuthenticatedBackendUser,
        private readonly ClientIpService $clientIpService,
    ) {}

    /**
     * @param list<string> $requestedScopes
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
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, revoked_client_id: string}
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

        $codeAudience = (string) ($codeRecord['audience'] ?? '');
        if ($codeAudience !== $resource) {
            throw new InvalidGrantException('resource parameter does not match the authorization request.');
        }

        if (!$this->validatePkce($codeVerifier, $codeRecord['code_challenge'])) {
            throw new InvalidGrantException('PKCE verification failed. Please check your code_verifier.');
        }

        if (!$this->tokenRepository->markCodeUsed($codeHash)) {
            $this->revokeTokensOfReplayedCode($codeHash);

            throw new InvalidGrantException('Authorization code has already been used.');
        }

        $scopes = array_values(array_filter(explode(' ', (string) $codeRecord['scopes'])));
        $codeWorkspaceUid = isset($codeRecord['workspace_uid']) && (int) $codeRecord['workspace_uid'] > 0
            ? (int) $codeRecord['workspace_uid']
            : null;

        $pair = $this->createTokenPair(
            (int) $codeRecord['be_user_uid'],
            $clientId,
            $scopes,
            $codeAudience,
            $codeWorkspaceUid,
        );

        $issued = $this->tokenRepository->findByTokenHash(hash('sha256', $pair['access_token']));
        if (null !== $issued) {
            $this->tokenRepository->linkCodeToToken($codeHash, (int) $issued['uid']);
        }

        return $pair;
    }

    /**
     * @param list<string> $scopes
     *
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string, revoked_client_id: string}
     */
    public function createAccessToken(
        int $beUserUid,
        string $clientId,
        array $scopes,
        string $audience,
        ?int $workspaceUid = null,
    ): array {
        $result = $this->createTokenPair($beUserUid, $clientId, $scopes, $audience, $workspaceUid, refreshable: false);
        unset($result['refresh_token']);

        return $result;
    }

    /**
     * @throws InvalidTokenException
     */
    public function validateToken(string $rawToken, string $canonicalResource, ?ServerRequestInterface $request = null): TokenData
    {
        $tokenHash = hash('sha256', $rawToken);
        $record = $this->tokenRepository->findByTokenHash($tokenHash);

        if (null === $record || 0 !== (int) $record['deleted']) {
            throw new InvalidTokenException('Token is invalid or has been revoked.');
        }

        if ((int) $record['expires_at'] < time()) {
            throw new InvalidTokenException('Token has expired. Please obtain a new token.');
        }

        $audience = (string) ($record['audience'] ?? '');
        if ('' === $audience) {
            throw new InvalidTokenException(
                'Token has no audience binding (legacy token issued before RFC 8707 enforcement). Please re-authorize.',
            );
        }
        if (!CanonicalResource::matches($audience, $canonicalResource)) {
            throw new InvalidTokenException('Token audience does not match this MCP server.');
        }

        $this->tokenRepository->updateLastUsed((int) $record['uid'], null !== $request ? $this->clientIpService->resolve($request) : '');

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
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, revoked_client_id: string}
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

        $now = time();

        if (0 !== (int) $existing['deleted'] && 0 === (int) $existing['rotated_at']) {
            throw new InvalidGrantException('Refresh token has been revoked. Please re-authorize.');
        }

        // Guards before the claim: a rejected refresh must not leave the token marked as rotated.
        $audience = $this->assertRefreshable($existing, $clientId, $resource);

        if (!$this->tokenRepository->claimRotation((int) $existing['uid'], $now)) {
            return $this->handleAlreadyRotatedToken($refreshHash, $clientId, $resource, $now);
        }

        $oldScopes = array_values(array_filter(explode(' ', (string) $existing['scopes'])));
        $validScopes = $this->filterScopesByPermissions($oldScopes, (int) $existing['be_user_uid']);

        $this->tokenRepository->markDeleted((int) $existing['uid']);

        return $this->createTokenPair(
            (int) $existing['be_user_uid'],
            $clientId,
            $validScopes,
            $audience,
            (int) ($existing['workspace_uid'] ?? 0),
            (int) $existing['family_id'] ?: (int) $existing['uid'],
            (int) $existing['uid'],
            (int) ($existing['session_credits_used'] ?? 0),
            $this->familyCreatedAt($existing),
        );
    }

    public function revokeToken(string $rawToken, string $clientId = ''): void
    {
        $tokenHash = hash('sha256', $rawToken);
        $row = $this->tokenRepository->findByTokenHash($tokenHash) ?? $this->tokenRepository->findByRefreshTokenHash($tokenHash);
        if (null === $row) {
            return;
        }

        if ('' !== $clientId && !hash_equals((string) $row['client_id'], $clientId)) {
            $this->logger->notice('Token revocation refused: the token was issued to another client', ['client_id' => $clientId]);

            return;
        }

        $this->tokenRepository->revokeFamily((int) $row['family_id'] ?: (int) $row['uid']);
    }

    public function isBackendUserActive(int $beUserUid): bool
    {
        $beUser = BackendUtility::getRecord('be_users', $beUserUid);
        if (null === $beUser || 0 !== (int) ($beUser['disable'] ?? 0) || 0 !== (int) ($beUser['deleted'] ?? 0)) {
            return false;
        }

        $now = time();
        $starttime = (int) ($beUser['starttime'] ?? 0);
        $endtime = (int) ($beUser['endtime'] ?? 0);

        return $starttime <= $now && (0 === $endtime || $endtime > $now);
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
        $backendUser = $this->tokenAuthenticatedBackendUser->createForUid($beUserUid);
        if (!is_array($backendUser->user)) {
            return [];
        }

        $previous = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $availableScopes = $this->permissionService->getAvailableScopes();
        } finally {
            if (null === $previous) {
                unset($GLOBALS['BE_USER']);
            } else {
                $GLOBALS['BE_USER'] = $previous;
            }
        }

        return array_values(array_intersect($requestedScopes, $availableScopes));
    }

    private function tokenLifetimeDays(): int
    {
        $configured = $this->configuredInt('mcpTokenLifetimeDays');

        return $configured > 0 ? $configured : self::DEFAULT_TOKEN_LIFETIME_DAYS;
    }

    private function accessTokenLifetimeMinutes(): int
    {
        $configured = $this->configuredInt('mcpAccessTokenLifetimeMinutes');

        return $configured > 0 ? $configured : self::DEFAULT_ACCESS_TOKEN_LIFETIME_MINUTES;
    }

    private function refreshIdleDays(): int
    {
        $configured = $this->configuredInt('mcpRefreshIdleDays');

        return $configured > 0 ? $configured : self::DEFAULT_REFRESH_IDLE_DAYS;
    }

    private function refreshMaxLifetimeDays(): int
    {
        $configured = $this->configuredInt('mcpRefreshMaxLifetimeDays');

        return $configured > 0 ? $configured : self::DEFAULT_REFRESH_MAX_LIFETIME_DAYS;
    }

    private function configuredInt(string $key): int
    {
        try {
            return (int) ($this->extensionConfiguration->get('ai_suite_mcp')[$key] ?? 0);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function familyCreatedAt(array $row): int
    {
        return (int) ($row['family_created_at'] ?? 0) ?: (int) $row['created_at'];
    }

    private function revokeTokensOfReplayedCode(string $codeHash): void
    {
        $tokenUid = (int) ($this->tokenRepository->findCodeByHash($codeHash)['token_uid'] ?? 0);
        $revoked = $tokenUid > 0 ? $this->tokenRepository->revokeFamily($tokenUid) : 0;

        $this->logger->critical('SECURITY: Authorization code replayed, revoked the tokens it had issued', [
            'token_uid' => $tokenUid,
            'revoked_tokens' => $revoked,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidGrantException
     */
    private function assertRefreshable(array $row, string $clientId, string $resource): string
    {
        if (!hash_equals((string) $row['client_id'], $clientId)) {
            throw new InvalidGrantException('Client ID does not match the token.');
        }

        if (!$this->isBackendUserActive((int) $row['be_user_uid'])) {
            $this->tokenRepository->markDeleted((int) $row['uid']);

            throw new InvalidGrantException('Your backend account has been deactivated. Please contact your administrator.');
        }

        if (((int) ($row['refresh_expires_at'] ?? 0) ?: (int) $row['expires_at']) < time()) {
            $this->tokenRepository->markDeleted((int) $row['uid']);

            throw new InvalidGrantException('Refresh token has expired because the connection was not used for too long. Please re-authorize.');
        }

        if (time() - $this->familyCreatedAt($row) > $this->refreshMaxLifetimeDays() * 86400) {
            $this->tokenRepository->revokeFamily((int) $row['family_id'] ?: (int) $row['uid']);

            throw new InvalidGrantException('This connection has reached its maximum lifetime. Please re-authorize.');
        }

        $audience = (string) ($row['audience'] ?? '');
        if ('' === $audience) {
            $this->tokenRepository->markDeleted((int) $row['uid']);

            throw new InvalidGrantException(
                'Refresh token has no audience binding (legacy token). Please re-authorize.',
            );
        }
        if ('' !== $resource && $resource !== $audience) {
            throw new InvalidGrantException('resource parameter does not match the bound audience.');
        }

        return $audience;
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, revoked_client_id: string}
     *
     * @throws InvalidGrantException
     */
    private function handleAlreadyRotatedToken(string $refreshHash, string $clientId, string $resource, int $now): array
    {
        $row = $this->tokenRepository->findByRefreshTokenHash($refreshHash);
        if (null === $row) {
            throw new InvalidGrantException('Refresh token is invalid.');
        }

        $rotatedAt = (int) $row['rotated_at'];
        $familyId = (int) $row['family_id'] ?: (int) $row['uid'];

        if ($rotatedAt > 0 && ($now - $rotatedAt) <= self::REFRESH_GRACE_SECONDS) {
            if ((int) $row['replaced_by'] > 0 && !$this->tokenRepository->hasLiveTokenInFamily($familyId)) {
                throw new InvalidGrantException('Refresh token has been revoked. Please re-authorize.');
            }

            $audience = $this->assertRefreshable($row, $clientId, $resource);

            $this->logger->info('Concurrent refresh within grace window — issuing an additional pair', [
                'client_id' => $clientId,
                'family_id' => $familyId,
                'age_seconds' => $now - $rotatedAt,
            ]);

            $scopes = array_values(array_filter(explode(' ', (string) $row['scopes'])));

            return $this->createTokenPair(
                (int) $row['be_user_uid'],
                $clientId,
                $this->filterScopesByPermissions($scopes, (int) $row['be_user_uid']),
                $audience,
                (int) ($row['workspace_uid'] ?? 0),
                $familyId,
                null,
                (int) ($row['session_credits_used'] ?? 0),
                $this->familyCreatedAt($row),
            );
        }

        $revokedCount = $this->tokenRepository->revokeFamily($familyId);

        $this->logger->critical('SECURITY: Refresh token reuse detected — possible token theft', [
            'be_user_uid' => $row['be_user_uid'],
            'client_id' => $row['client_id'],
            'family_id' => $familyId,
            'rotated_age_seconds' => $rotatedAt > 0 ? $now - $rotatedAt : null,
            'revoked_tokens' => $revokedCount,
        ]);

        throw new InvalidGrantException(sprintf(
            'Security alert: This refresh token has already been used. %s Please re-authorize.',
            1 === $revokedCount
                ? '1 session has been revoked.'
                : sprintf('%d sessions have been revoked.', $revokedCount),
        ));
    }

    /**
     * @param list<string> $scopes
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, revoked_client_id: string}
     */
    private function createTokenPair(
        int $beUserUid,
        string $clientId,
        array $scopes,
        string $audience,
        ?int $workspaceUid = null,
        int $familyId = 0,
        ?int $predecessorUid = null,
        int $sessionCreditsUsed = 0,
        int $familyCreatedAt = 0,
        bool $refreshable = true,
    ): array {
        $displaced = null;
        $activeCount = $this->tokenRepository->countActiveTokensForUser($beUserUid);
        if ($activeCount >= self::MAX_ACTIVE_TOKENS_PER_USER) {
            $displaced = $this->tokenRepository->revokeOldestTokenForUser($beUserUid);
            if (null !== $displaced) {
                $this->logger->warning('MCP token limit reached, revoked the oldest token', [
                    'be_user_uid' => $beUserUid,
                    'revoked_client_id' => $displaced['client_id'],
                    'limit' => self::MAX_ACTIVE_TOKENS_PER_USER,
                ]);
            }
        }

        $rawAccessToken = bin2hex(random_bytes(32));
        $rawRefreshToken = bin2hex(random_bytes(32));

        $expiresIn = $refreshable ? $this->accessTokenLifetimeMinutes() * 60 : $this->tokenLifetimeDays() * 86400;

        $uid = $this->tokenRepository->createToken([
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
            'refresh_expires_at' => $refreshable ? time() + $this->refreshIdleDays() * 86400 : 0,
            'session_credits_used' => max(0, $sessionCreditsUsed),
            'deleted' => 0,
            'family_id' => $familyId,
            'replaced_by' => 0,
            'rotated_at' => 0,
            'family_created_at' => $familyCreatedAt ?: time(),
        ]);

        if (null !== $predecessorUid) {
            $this->tokenRepository->linkSuccessor($predecessorUid, $uid);
        }

        return [
            'access_token' => $rawAccessToken,
            'refresh_token' => $rawRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'scope' => implode(' ', $scopes),
            'revoked_client_id' => $displaced['client_id'] ?? '',
        ];
    }
}
