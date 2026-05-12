<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use AutoDudes\AiSuite\Service\ViewFactoryService;
use AutoDudes\AiSuiteMcp\Domain\Repository\SysWorkspaceRepository;
use AutoDudes\AiSuiteMcp\Mcp\McpPermissionService;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use AutoDudes\AiSuiteMcp\Mcp\Service\ClientIpService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RateLimiterService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TokenAuthenticatedBackendUserFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\View\AuthenticationStyleInformation;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderPropertyManager;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderRegistry;
use TYPO3\CMS\Core\Authentication\Mfa\MfaViewType;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use TYPO3\CMS\Core\SystemResource\Type\PublicResourceInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * OAuth 2.1 Authorization Endpoint.
 * GET /aisuite-mcp/oauth/authorize — shows login form
 * POST /aisuite-mcp/oauth/authorize — processes login + consent.
 */
class AuthorizationEndpoint
{
    private const MFA_TICKET_TTL = 60;
    private const MFA_TICKET_PURPOSE = 'mcp-oauth-mfa';

    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly McpPermissionService $permissionService,
        private readonly RateLimiterService $rateLimiter,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly AuthenticationStyleInformation $authStyleInfo,
        private readonly MfaProviderRegistry $mfaProviderRegistry,
        private readonly ViewFactoryService $viewFactoryService,
        private readonly Typo3Version $typo3Version,
        private readonly SysWorkspaceRepository $sysWorkspaceRepository,
        private readonly ClientIpService $clientIpService,
        private readonly TokenAuthenticatedBackendUserFactory $tokenAuthenticatedBackendUserFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Rate limiting per IP
        $ip = $this->clientIpService->resolve($request);

        try {
            $this->rateLimiter->checkAndIncrement('auth_'.$ip);
        } catch (\RuntimeException $e) {
            $this->logger->warning('OAuth authorize endpoint rate limit exceeded', [
                'ip' => $ip,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'rate_limit_exceeded',
                'error_description' => $e->getMessage(),
            ], 429);
        }

        if ('GET' === $request->getMethod()) {
            return $this->showLoginForm($request);
        }

        if ('POST' === $request->getMethod()) {
            return $this->processLogin($request);
        }

        return new JsonResponse(['error' => 'method_not_allowed'], 405);
    }

    /**
     * GET: Validate parameters and show login form.
     */
    private function showLoginForm(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        // Validate required parameters
        $validation = $this->validateAuthorizationParams($params);
        if (null !== $validation) {
            return $validation;
        }

        // Render login form
        return new HtmlResponse($this->renderOAuthView('OAuth/Login', [
            'clientId' => $params['client_id'],
            'redirectUri' => $params['redirect_uri'],
            'codeChallenge' => $params['code_challenge'],
            'scope' => $params['scope'] ?? 'mcp:read',
            'state' => $params['state'],
            'resource' => (string) ($params['resource'] ?? ''),
            'isLocalhost' => $this->isLocalhostRedirect($params['redirect_uri']),
        ], $request));
    }

    /**
     * POST: Authenticate user, show consent or redirect with code.
     */
    private function processLogin(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $clientId = (string) ($body['client_id'] ?? '');
        $redirectUri = (string) ($body['redirect_uri'] ?? '');
        $codeChallenge = (string) ($body['code_challenge'] ?? '');
        $scope = (string) ($body['scope'] ?? 'mcp:read');
        $state = (string) ($body['state'] ?? '');
        $resource = (string) ($body['resource'] ?? '');
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $consentAction = (string) ($body['consent'] ?? '');
        $mfaTicket = (string) ($body['mfa_ticket'] ?? '');

        // If this is an MFA verification submission
        if ('' !== $mfaTicket) {
            return $this->processMfa($request, $body);
        }

        // If this is a consent submission (user already authenticated)
        if ('' !== $consentAction && isset($body['be_user_uid'])) {
            return $this->handleConsent($body);
        }

        // Authenticate user
        $beUserUid = $this->authenticateUser($username, $password);

        if (null === $beUserUid) {
            // Login failed — show form again with error
            return new HtmlResponse($this->renderOAuthView('OAuth/Login', [
                'clientId' => $clientId,
                'redirectUri' => $redirectUri,
                'codeChallenge' => $codeChallenge,
                'scope' => $scope,
                'state' => $state,
                'resource' => $resource,
                'loginError' => true,
                'isLocalhost' => $this->isLocalhostRedirect($redirectUri),
            ], $request));
        }

        // Check MCP access permission
        if (!$this->userHasMcpAccess($beUserUid)) {
            return new HtmlResponse($this->renderOAuthView('OAuth/Login', [
                'clientId' => $clientId,
                'redirectUri' => $redirectUri,
                'codeChallenge' => $codeChallenge,
                'scope' => $scope,
                'state' => $state,
                'resource' => $resource,
                'accessDenied' => true,
                'isLocalhost' => $this->isLocalhostRedirect($redirectUri),
            ], $request));
        }

        // Initialize backend user context so permission checks work
        $backendUser = $this->initBackendUser($beUserUid);

        // MFA required? → render MFA challenge
        if ($this->mfaProviderRegistry->hasActiveProviders($backendUser)) {
            return $this->renderMfaChallenge(
                $backendUser,
                $beUserUid,
                $request,
                $clientId,
                $redirectUri,
                $codeChallenge,
                $scope,
                $state,
                $resource,
            );
        }

        return $this->renderConsent($beUserUid, $clientId, $redirectUri, $codeChallenge, $scope, $state, $resource, $request);
    }

    /**
     * Handle MFA verification submission.
     *
     * @param array<string, mixed> $body
     */
    private function processMfa(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $ticket = (string) ($body['mfa_ticket'] ?? '');
        $clientId = (string) ($body['client_id'] ?? '');
        $redirectUri = (string) ($body['redirect_uri'] ?? '');
        $codeChallenge = (string) ($body['code_challenge'] ?? '');
        $scope = (string) ($body['scope'] ?? 'mcp:read');
        $state = (string) ($body['state'] ?? '');
        $resource = (string) ($body['resource'] ?? '');
        $ip = $this->clientIpService->resolve($request);

        $beUserUid = $this->verifyMfaTicket($ticket, $ip);
        if (null === $beUserUid) {
            $this->logger->warning('MCP OAuth: invalid or expired MFA ticket', ['ip' => $ip]);
            $separator = str_contains($redirectUri, '?') ? '&' : '?';

            return new RedirectResponse(
                $redirectUri.$separator.'error=access_denied&error_description='.urlencode('MFA session expired').'&state='.urlencode($state),
            );
        }

        // Dedicated rate limit bucket for MFA verify attempts per user
        try {
            $this->rateLimiter->checkAndIncrement('mfa_'.$beUserUid);
        } catch (\RuntimeException $e) {
            $this->logger->warning('OAuth MFA verify endpoint rate limit exceeded', [
                'beUserUid' => $beUserUid,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'rate_limit_exceeded',
                'error_description' => $e->getMessage(),
            ], 429);
        }

        $backendUser = $this->initBackendUser($beUserUid);

        $provider = $this->mfaProviderRegistry->getFirstAuthenticationAwareProvider($backendUser);
        if (null === $provider) {
            // No active provider anymore (unlikely, but be safe) → proceed to consent
            return $this->renderConsent($beUserUid, $clientId, $redirectUri, $codeChallenge, $scope, $state, $resource, $request);
        }

        $propertyManager = MfaProviderPropertyManager::create($provider, $backendUser);

        if ($provider->isLocked($propertyManager)) {
            $this->logger->warning('MCP OAuth: MFA provider locked', [
                'be_user_uid' => $beUserUid,
                'provider' => $provider->getIdentifier(),
            ]);
            $separator = str_contains($redirectUri, '?') ? '&' : '?';

            return new RedirectResponse(
                $redirectUri.$separator.'error=access_denied&error_description='.urlencode('MFA provider locked').'&state='.urlencode($state),
            );
        }

        if (!$provider->canProcess($request) || !$provider->verify($request, $propertyManager)) {
            $this->logger->notice('MCP OAuth: MFA verification failed', [
                'be_user_uid' => $beUserUid,
                'provider' => $provider->getIdentifier(),
            ]);

            return $this->renderMfaChallenge(
                $backendUser,
                $beUserUid,
                $request,
                $clientId,
                $redirectUri,
                $codeChallenge,
                $scope,
                $state,
                $resource,
                true,
            );
        }

        $this->logger->info('MCP OAuth: MFA verification successful', [
            'be_user_uid' => $beUserUid,
            'provider' => $provider->getIdentifier(),
        ]);

        return $this->renderConsent($beUserUid, $clientId, $redirectUri, $codeChallenge, $scope, $state, $resource, $request);
    }

    private function renderMfaChallenge(
        BackendUserAuthentication $backendUser,
        int $beUserUid,
        ServerRequestInterface $request,
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        string $scope,
        string $state,
        string $resource,
        bool $failed = false,
    ): ResponseInterface {
        $provider = $this->mfaProviderRegistry->getFirstAuthenticationAwareProvider($backendUser);
        if (null === $provider) {
            // No active provider (should not happen here — defensive fallback)
            return $this->renderConsent($beUserUid, $clientId, $redirectUri, $codeChallenge, $scope, $state, $resource, $request);
        }

        $propertyManager = MfaProviderPropertyManager::create($provider, $backendUser);
        $providerResponse = $provider->handleRequest($request, $propertyManager, MfaViewType::AUTH);

        $ip = $this->clientIpService->resolve($request);
        $ticket = $this->createMfaTicket($beUserUid, $ip);

        return new HtmlResponse($this->renderOAuthView('OAuth/Mfa', [
            'clientId' => $clientId,
            'redirectUri' => $redirectUri,
            'codeChallenge' => $codeChallenge,
            'scope' => $scope,
            'state' => $state,
            'resource' => $resource,
            'mfaTicket' => $ticket,
            'providerTitle' => $provider->getTitle(),
            'providerContent' => (string) $providerResponse->getBody(),
            'mfaError' => $failed,
            'isLocalhost' => $this->isLocalhostRedirect($redirectUri),
        ], $request));
    }

    private function renderConsent(
        int $beUserUid,
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        string $scope,
        string $state,
        string $resource,
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        $requestedScopes = array_filter(explode(' ', $scope));
        $availableScopes = $this->permissionService->getAvailableScopes();
        $grantableScopes = array_values(array_intersect($requestedScopes, $availableScopes));

        return new HtmlResponse($this->renderOAuthView('OAuth/Consent', [
            'clientId' => $clientId,
            'redirectUri' => $redirectUri,
            'codeChallenge' => $codeChallenge,
            'state' => $state,
            'resource' => $resource,
            'beUserUid' => $beUserUid,
            'scopes' => $this->getScopeDescriptions($grantableScopes),
            'isLocalhost' => $this->isLocalhostRedirect($redirectUri),
            'availableWorkspaces' => $this->resolveAvailableWorkspaces($beUserUid),
            'defaultWorkspaceLabel' => $this->resolveDefaultWorkspaceLabel($beUserUid),
        ], $request));
    }

    /**
     * Returns the list of workspaces the BE user may select from in the consent form.
     * Empty when ext:workspaces is not loaded — the template hides the dropdown then.
     *
     * @return list<array{uid: int, title: string}>
     */
    private function resolveAvailableWorkspaces(int $beUserUid): array
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return [];
        }

        $backendUser = $this->initBackendUser($beUserUid);

        $available = [];
        foreach ($this->sysWorkspaceRepository->findAll() as $row) {
            if ($backendUser->checkWorkspace($row['uid'])) {
                $available[] = $row;
            }
        }

        return $available;
    }

    private function resolveDefaultWorkspaceLabel(int $beUserUid): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return 'Live (workspaces not installed)';
        }

        $backendUser = $this->initBackendUser($beUserUid);
        $defaultWs = (int) $backendUser->workspace;
        if (0 === $defaultWs) {
            return 'User default (Live)';
        }

        return sprintf('User default (Workspace %d)', $defaultWs);
    }

    private function initBackendUser(int $beUserUid): BackendUserAuthentication
    {
        $backendUser = $this->tokenAuthenticatedBackendUserFactory->createForUid($beUserUid);
        $GLOBALS['BE_USER'] = $backendUser;

        return $backendUser;
    }

    /**
     * Create an HMAC-signed MFA ticket carrying the authenticated uid across the MFA step.
     * Format: base64url(uid|ip|exp).base64url(hmac).
     */
    private function createMfaTicket(int $beUserUid, string $ip): string
    {
        $expires = time() + self::MFA_TICKET_TTL;
        $payload = $beUserUid.'|'.$ip.'|'.$expires;
        $payloadEncoded = $this->base64UrlEncode($payload);
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $payloadEncoded, $this->getHmacSecret(), true));

        return $payloadEncoded.'.'.$signature;
    }

    /**
     * Verify an MFA ticket. Returns the uid on success, null otherwise.
     */
    private function verifyMfaTicket(string $ticket, string $ip): ?int
    {
        $parts = explode('.', $ticket);
        if (2 !== count($parts)) {
            return null;
        }
        [$payloadEncoded, $signature] = $parts;

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $payloadEncoded, $this->getHmacSecret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = $this->base64UrlDecode($payloadEncoded);
        if (null === $payload) {
            return null;
        }

        $segments = explode('|', $payload);
        if (3 !== count($segments)) {
            return null;
        }

        [$uid, $ticketIp, $expires] = $segments;
        if ($ticketIp !== $ip || (int) $expires < time()) {
            return null;
        }

        return (int) $uid;
    }

    private function getHmacSecret(): string
    {
        $key = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ('' === $key) {
            throw new \RuntimeException('encryptionKey is not configured.', 1744800000);
        }

        return $key.'|'.self::MFA_TICKET_PURPOSE;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }

    /**
     * Handle consent form submission.
     *
     * @param array<string, mixed> $body
     */
    private function handleConsent(array $body): ResponseInterface
    {
        $consentAction = (string) ($body['consent'] ?? '');
        $beUserUid = (int) ($body['be_user_uid'] ?? 0);
        $clientId = (string) ($body['client_id'] ?? '');
        $redirectUri = (string) ($body['redirect_uri'] ?? '');
        $codeChallenge = (string) ($body['code_challenge'] ?? '');
        $state = (string) ($body['state'] ?? '');
        $resource = (string) ($body['resource'] ?? '');

        if ('deny' === $consentAction) {
            $separator = str_contains($redirectUri, '?') ? '&' : '?';

            return new RedirectResponse($redirectUri.$separator.'error=access_denied&state='.urlencode($state));
        }

        // Re-validate the resource binding now that we're about to mint the code. The hidden
        // field could have been tampered with after the initial /authorize GET.
        if ('' === $resource || !CanonicalResource::matches($resource)) {
            $separator = str_contains($redirectUri, '?') ? '&' : '?';

            return new RedirectResponse(
                $redirectUri.$separator.'error=invalid_target&error_description='.urlencode('resource parameter is missing or does not match this MCP server.').'&state='.urlencode($state),
            );
        }

        // Initialize backend user context so permission checks work
        $backendUser = $this->tokenAuthenticatedBackendUserFactory->createForUid($beUserUid);
        $GLOBALS['BE_USER'] = $backendUser;

        // Determine granted scopes
        if ('all' === $consentAction) {
            $grantedScopes = $this->permissionService->getAvailableScopes();
        } else {
            $grantedScopes = (array) ($body['scopes'] ?? []);
        }

        // Resolve workspace selection from the consent form. Body field is optional —
        // 0 / missing / inaccessible workspace falls back to "user default" (null).
        $workspaceUid = (int) ($body['workspace_uid'] ?? 0);
        if ($workspaceUid > 0 && false === $backendUser->checkWorkspace($workspaceUid)) {
            $workspaceUid = 0;
        }

        // Create authorization code
        $rawCode = $this->oauthService->createAuthorizationCode(
            $beUserUid,
            $clientId,
            $redirectUri,
            $codeChallenge,
            array_values($grantedScopes),
            $resource,
            $workspaceUid > 0 ? $workspaceUid : null,
        );

        $this->logger->info('Authorization code issued', [
            'client_id' => $clientId,
            'be_user_uid' => $beUserUid,
            'scopes' => implode(' ', $grantedScopes),
            'workspace_uid' => $workspaceUid,
            'audience' => $resource,
        ]);

        // Redirect with code
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return new RedirectResponse(
            $redirectUri.$separator.'code='.urlencode($rawCode).'&state='.urlencode($state),
        );
    }

    /**
     * Validate authorization request parameters.
     *
     * @param array<string, mixed> $params
     */
    private function validateAuthorizationParams(array $params): ?ResponseInterface
    {
        // response_type must be 'code'
        if (($params['response_type'] ?? '') !== 'code') {
            return new JsonResponse([
                'error' => 'unsupported_response_type',
                'error_description' => 'Only response_type=code is supported.',
            ], 400);
        }

        // client_id required
        if (empty($params['client_id'])) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'client_id is required.'], 400);
        }

        // redirect_uri required and validated (Q3)
        if (empty($params['redirect_uri']) || !$this->validateRedirectUri($params['redirect_uri'])) {
            $this->logger->warning('MCP OAuth: redirect_uri validation failed', [
                'redirect_uri' => $params['redirect_uri'] ?? '(empty)',
                'client_id' => $params['client_id'] ?? '(empty)',
                'all_params' => array_keys($params),
            ]);

            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'A valid redirect_uri is required. Received: '.($params['redirect_uri'] ?? '(empty)'),
            ], 400);
        }

        // code_challenge required (PKCE)
        if (empty($params['code_challenge'])) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'code_challenge is required (PKCE).',
            ], 400);
        }

        // code_challenge_method must be S256 (S20)
        if (($params['code_challenge_method'] ?? 'S256') !== 'S256') {
            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'Only code_challenge_method=S256 is supported.',
            ], 400);
        }

        // state required, min 22 chars.  OAuth 2.1 BCP (RFC 9700 §4.7) recommends >=128 bit entropy.
        $state = $params['state'] ?? '';
        if (strlen($state) < 22) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'state parameter is required and must be at least 22 characters (CSRF protection).',
            ], 400);
        }

        // resource required and bound to this MCP server (RFC 8707, MCP 2025-11-25 spec)
        $resource = (string) ($params['resource'] ?? '');
        if ('' === $resource) {
            return new JsonResponse([
                'error' => 'invalid_target',
                'error_description' => sprintf(
                    'resource parameter is required (RFC 8707). Expected: %s',
                    CanonicalResource::get(),
                ),
            ], 400);
        }
        if (!CanonicalResource::matches($resource)) {
            return new JsonResponse([
                'error' => 'invalid_target',
                'error_description' => sprintf(
                    'resource parameter does not match this MCP server. Expected: %s',
                    CanonicalResource::get(),
                ),
            ], 400);
        }

        // Client ID allowlist check
        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        $allowedClientIds = array_filter(
            array_map('trim', explode(',', (string) ($extConf['mcpAllowedClientIds'] ?? ''))),
        );
        if (!empty($allowedClientIds) && !in_array($params['client_id'], $allowedClientIds, true)) {
            return new JsonResponse([
                'error' => 'unauthorized_client',
                'error_description' => 'This client is not authorized to use this server.',
            ], 400);
        }

        return null;
    }

    /**
     * Validate redirect URI.
     * Localhost always allowed. External URIs only with allowlist.
     * Production + empty allowlist = only localhost allowed.
     */
    private function validateRedirectUri(string $redirectUri): bool
    {
        $parsed = parse_url($redirectUri);
        $scheme = $parsed['scheme'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'] ?? '';

        // Localhost always allowed
        if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return true;
        }

        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        $allowedRedirectUris = array_filter(
            array_map('trim', explode(',', (string) ($extConf['mcpAllowedRedirectUris'] ?? ''))),
        );

        // Empty allowlist in production = localhost only
        if (empty($allowedRedirectUris)) {
            return !Environment::getContext()->isProduction();
        }

        foreach ($allowedRedirectUris as $allowed) {
            if (str_starts_with($redirectUri, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Authenticate a backend user by username and password.
     */
    private function authenticateUser(string $username, string $password): ?int
    {
        if ('' === $username || '' === $password) {
            return null;
        }

        try {
            // setBeUserByName applies userConstraints() (deleted=0, disable=0, starttime/endtime),
            // mirroring TYPO3's own login pre-check.
            $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $backendUser->setBeUserByName($username);
            $user = $backendUser->user;

            if (null === $user) {
                $this->logger->notice('Authentication failed: user not found', ['username' => $username]);

                return null;
            }

            $hashInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)->get($user['password'], 'BE');
            if (!$hashInstance->checkPassword($password, $user['password'])) {
                $this->logger->notice('Authentication failed: invalid password', ['username' => $username]);

                return null;
            }

            return (int) $user['uid'];
        } catch (InvalidPasswordHashException $e) {
            $this->logger->notice('Authentication failed: password hash error', ['username' => $username, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->notice('Authentication failed', ['username' => $username, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function userHasMcpAccess(int $beUserUid): bool
    {
        try {
            $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $backendUser->setBeUserByUid($beUserUid);
            $backendUser->fetchGroupData();

            return $backendUser->check('custom_options', 'tx_aisuite_features:enable_mcp_access');
        } catch (\Throwable $e) {
            $this->logger->warning('AuthorizationEndpoint: MCP access check failed for backend user, denying access', [
                'beUserUid' => $beUserUid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function isLocalhostRedirect(string $redirectUri): bool
    {
        $host = parse_url($redirectUri, PHP_URL_HOST) ?? '';

        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, array{key: string, label: string}>
     */
    private function getScopeDescriptions(array $scopes): array
    {
        $descriptions = [
            'mcp:read' => 'Seiten, Content und Dateien analysieren',
            'mcp:write' => 'Inhalte anlegen, ändern oder löschen (immer mit Vorschau und Bestätigung)',
            'mcp:generate' => 'SEO-Metadaten, Content und Seitenstrukturen generieren',
            'mcp:translate' => 'Seiten und Inhalte in andere Sprachen übersetzen',
            'mcp:image' => 'Bilder mit KI generieren (GPTImage, Midjourney, Flux)',
            'mcp:workflow' => 'Massenaktionen und Hintergrund-Tasks für viele Seiten gleichzeitig durchführen',
            'mcp:easy-language' => 'Inhalte in Leichte Sprache umwandeln (BFSG-konform)',
            'mcp:glossary' => 'Glossar für konsistente Übersetzungen nutzen',
            'mcp:manage' => 'Prompt-Vorlagen und Redaktionsrichtlinien verwalten',
        ];

        $result = [];
        foreach ($scopes as $scope) {
            $result[$scope] = [
                'key' => $scope,
                'label' => $descriptions[$scope] ?? $scope,
            ];
        }

        return $result;
    }

    /**
     * Render an OAuth Fluid template.
     *
     * @param array<string, mixed> $params
     */
    private function renderOAuthView(
        string $templateName,
        array $params,
        ?ServerRequestInterface $request = null,
    ): string {
        return $this->viewFactoryService->renderView(
            $templateName,
            ['EXT:ai_suite_mcp/Resources/Private/Templates/'],
            ['EXT:ai_suite_mcp/Resources/Private/Partials/'],
            ['EXT:ai_suite_mcp/Resources/Private/Layouts/'],
            array_merge($this->getBackendStyleVariables($request), $params),
            $request,
        );
    }

    /**
     * Collect TYPO3 backend branding settings for the OAuth templates.
     *
     * @return array<string, mixed>
     */
    private function getBackendStyleVariables(?ServerRequestInterface $request = null): array
    {
        $backendConf = (array) $this->extensionConfiguration->get('backend');
        $faviconPath = (string) ($backendConf['backendFavicon'] ?? '');

        if ($this->typo3Version->getMajorVersion() >= 14) {
            [$logoUrl, $faviconUrl, $backgroundImageStyles] = $this->resolveStyles($faviconPath, $request);
        } else {
            [$logoUrl, $faviconUrl, $backgroundImageStyles] = $this->resolveStylesLegacy($faviconPath);
        }

        return [
            'backgroundImageStyles' => $backgroundImageStyles,
            'highlightColorStyles' => $this->authStyleInfo->getHighlightColorStyles(),
            'loginLogoUrl' => $logoUrl,
            'loginLogoAlt' => $this->authStyleInfo->getLogoAlt(),
            'hasCustomLogo' => '' !== $logoUrl,
            'faviconUrl' => $faviconUrl,
            'footerNote' => $this->authStyleInfo->getFooterNote(),
            'aiSuiteIconUrl' => PathUtility::getPublicResourceWebPath(
                'EXT:ai_suite/Resources/Public/Icons/Extension.svg',
            ),
            'siteName' => (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'TYPO3 AI Suite'),
        ];
    }

    /**
     * v14+ branch: resolve resources via SystemResourceFactory + SystemResourcePublisher.
     *
     * @return array{0: string, 1: string, 2: string} [logoUrl, faviconUrl, backgroundImageStyles]
     */
    private function resolveStyles(string $faviconPath, ?ServerRequestInterface $request): array
    {
        $factory = GeneralUtility::makeInstance(SystemResourceFactory::class);
        $publisher = GeneralUtility::makeInstance(SystemResourcePublisherInterface::class);

        $logoUrl = '';
        $logoResource = $this->authStyleInfo->getLogo();
        if ($logoResource instanceof PublicResourceInterface) {
            try {
                $logoUrl = (string) $publisher->generateUri($logoResource, $request);
            } catch (\Throwable $e) {
                $this->logger->warning('MCP OAuth: could not resolve login logo URI', ['exception' => $e]);
            }
        }

        $faviconUrl = '';
        if ('' !== $faviconPath) {
            try {
                $faviconResource = $factory->createPublicResource($faviconPath);
                $faviconUrl = (string) $publisher->generateUri($faviconResource, $request);
            } catch (\Throwable $e) {
                $this->logger->warning('MCP OAuth: could not resolve favicon URI', ['exception' => $e, 'path' => $faviconPath]);
            }
        }

        $backgroundImageStyles = null !== $request
            ? $this->authStyleInfo->getBackgroundImageStyles($request)
            : '';

        return [$logoUrl, $faviconUrl, $backgroundImageStyles];
    }

    /**
     * v12/v13 branch: use the legacy AuthenticationStyleInformation API.
     *
     * @return array{0: string, 1: string, 2: string} [logoUrl, faviconUrl, backgroundImageStyles]
     */
    private function resolveStylesLegacy(string $faviconPath): array
    {
        $logoPath = (string) $this->authStyleInfo->{'getLogo'}();
        $logoUrl = '' !== $logoPath
            ? (string) $this->authStyleInfo->{'getUriForFileName'}($logoPath)
            : '';

        $faviconUrl = '' !== $faviconPath
            ? (string) $this->authStyleInfo->{'getUriForFileName'}($faviconPath)
            : '';

        $backgroundImageStyles = (string) $this->authStyleInfo->{'getBackgroundImageStyles'}();

        return [$logoUrl, $faviconUrl, $backgroundImageStyles];
    }
}
