<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use AutoDudes\AiSuite\Service\IconService;
use AutoDudes\AiSuite\Service\LoginStyleService;
use AutoDudes\AiSuite\Service\ViewFactoryService;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\RateLimitPolicy;
use AutoDudes\AiSuiteMcp\Domain\Repository\OAuthClientRepository;
use AutoDudes\AiSuiteMcp\Mcp\Exception\RateLimitExceededException;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\RedirectUriPolicy;
use AutoDudes\AiSuiteMcp\Mcp\Service\BackendCredentialService;
use AutoDudes\AiSuiteMcp\Mcp\Service\ClientIpService;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpWorkspaceOptionsService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthTicketService;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RateLimiterService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TokenAuthenticatedBackendUserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\View\AuthenticationStyleInformation;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderPropertyManager;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderRegistry;
use TYPO3\CMS\Core\Authentication\Mfa\MfaViewType;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locales;

class AuthorizationEndpoint
{
    private const LABELS = 'LLL:EXT:ai_suite_mcp/Resources/Private/Language/locallang_oauth.xlf:aiSuite.mcp.oauth.';

    private const PAGE_LABELS = [
        'titleConnect', 'titleAuthorize', 'titleMfa', 'wouldLikeToConnect', 'requestsAccess', 'mfaRequired',
        'localConnection', 'redirectsTo', 'logoAlt', 'loginError', 'accessDenied', 'mfaSetupRequired', 'username', 'password', 'signIn', 'mfaError',
        'verify', 'bindWorkspace', 'workspace', 'grantAll', 'customize', 'grantSelected', 'deny', 'poweredBy', 'product',
    ];

    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly PermissionService $permissionService,
        private readonly RateLimiterService $rateLimiter,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly AuthenticationStyleInformation $authStyleInfo,
        private readonly MfaProviderRegistry $mfaProviderRegistry,
        private readonly ViewFactoryService $viewFactoryService,
        private readonly LoginStyleService $loginStyleService,
        private readonly McpWorkspaceOptionsService $workspaceOptions,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly Locales $locales,
        private readonly ClientIpService $clientIpService,
        private readonly TokenAuthenticatedBackendUserService $tokenAuthenticatedBackendUser,
        private readonly IconService $iconService,
        private readonly BackendCredentialService $backendCredentials,
        private readonly OAuthTicketService $oauthTicketService,
        private readonly OAuthClientRepository $clientRepository,
        private readonly RedirectUriPolicy $redirectUriPolicy,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $ip = $this->clientIpService->resolve($request);

        try {
            $this->rateLimiter->checkAndIncrement('auth_'.$ip, RateLimitPolicy::oauthEndpoint());
        } catch (RateLimitExceededException $e) {
            $this->logger->warning('OAuth authorize endpoint rate limit exceeded', [
                'ip' => $ip,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'rate_limit_exceeded',
                'error_description' => $e->getMessage(),
            ], 429, ['Retry-After' => (string) $e->retryAfter]);
        }

        if ('GET' === $request->getMethod()) {
            return $this->showLoginForm($request);
        }

        if ('POST' === $request->getMethod()) {
            return $this->processLogin($request);
        }

        return new JsonResponse(['error' => 'method_not_allowed'], 405);
    }

    private function showLoginForm(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $validation = $this->validateAuthorizationParams($params, CanonicalResource::get($request));
        if (null !== $validation) {
            return $validation;
        }

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

        if ('' !== $mfaTicket) {
            return $this->processMfa($request, $body);
        }

        if ('' !== $consentAction) {
            return $this->handleConsent($request, $body);
        }

        if (null === $this->resolveClient($clientId, $redirectUri)) {
            return $this->invalidClientResponse();
        }

        $beUserUid = $this->backendCredentials->authenticate($username, $password, $request);

        if (null === $beUserUid) {
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

        if (!$this->backendCredentials->hasMcpAccess($beUserUid)) {
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

        $backendUser = $this->initBackendUser($beUserUid);

        if (!$this->mfaProviderRegistry->hasActiveProviders($backendUser) && $backendUser->isMfaSetupRequired()) {
            $this->logger->notice('MCP OAuth: refused a login that requires MFA the user has not set up', ['be_user_uid' => $beUserUid]);

            return new HtmlResponse($this->renderOAuthView('OAuth/Login', [
                'clientId' => $clientId,
                'redirectUri' => $redirectUri,
                'codeChallenge' => $codeChallenge,
                'scope' => $scope,
                'state' => $state,
                'resource' => $resource,
                'mfaSetupRequired' => true,
                'isLocalhost' => $this->isLocalhostRedirect($redirectUri),
            ], $request));
        }

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

        $beUserUid = $this->oauthTicketService->verifyMfaTicket($ticket, $ip);
        if (null === $this->resolveClient($clientId, $redirectUri)) {
            return $this->invalidClientResponse();
        }

        if (null === $beUserUid) {
            $this->logger->warning('MCP OAuth: invalid or expired MFA ticket', ['ip' => $ip]);
            $separator = str_contains($redirectUri, '?') ? '&' : '?';

            return new RedirectResponse(
                $redirectUri.$separator.'error=access_denied&error_description='.urlencode('MFA session expired').'&state='.urlencode($state),
            );
        }

        try {
            $this->rateLimiter->checkAndIncrement('mfa_'.$beUserUid, RateLimitPolicy::mfaVerify());
        } catch (RateLimitExceededException $e) {
            $this->logger->warning('OAuth MFA verify endpoint rate limit exceeded', [
                'beUserUid' => $beUserUid,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'rate_limit_exceeded',
                'error_description' => $e->getMessage(),
            ], 429, ['Retry-After' => (string) $e->retryAfter]);
        }

        $backendUser = $this->initBackendUser($beUserUid);

        $provider = $this->mfaProviderRegistry->getFirstAuthenticationAwareProvider($backendUser);
        if (null === $provider) {
            return new JsonResponse(['error' => 'access_denied', 'error_description' => 'No MFA provider of this account can verify a login.'], 403);
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
            return new JsonResponse(['error' => 'access_denied', 'error_description' => 'No MFA provider of this account can verify a login.'], 403);
        }

        $propertyManager = MfaProviderPropertyManager::create($provider, $backendUser);
        $providerResponse = $provider->handleRequest($request, $propertyManager, MfaViewType::AUTH);

        $ip = $this->clientIpService->resolve($request);
        $ticket = $this->oauthTicketService->createMfaTicket($beUserUid, $ip);

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
        ServerRequestInterface $request,
    ): ResponseInterface {
        $bindingError = $this->validateConsentBindings($clientId, $redirectUri, $codeChallenge, $resource, CanonicalResource::get($request));
        if (null !== $bindingError) {
            return $bindingError;
        }

        $requestedScopes = array_filter(explode(' ', $scope));
        $availableScopes = $this->permissionService->getAvailableScopes();
        $grantableScopes = array_values(array_intersect($requestedScopes, $availableScopes));

        $ip = $this->clientIpService->resolve($request);
        $consentTicket = $this->oauthTicketService->createConsentTicket($beUserUid, $clientId, $redirectUri, $codeChallenge, $scope, $resource, $ip);
        $backendUser = $this->initBackendUser($beUserUid);
        $languageService = $this->languageServiceFor($request);

        return new HtmlResponse($this->renderOAuthView('OAuth/Consent', [
            'clientId' => $clientId,
            'redirectUri' => $redirectUri,
            'codeChallenge' => $codeChallenge,
            'state' => $state,
            'resource' => $resource,
            'consentTicket' => $consentTicket,
            'scopes' => $this->getScopeDescriptions($grantableScopes, $languageService),
            'isLocalhost' => $this->isLocalhostRedirect($redirectUri),
            'availableWorkspaces' => $this->workspaceOptions->availableFor($backendUser),
            'defaultWorkspaceLabel' => $this->workspaceOptions->defaultLabel($backendUser, $languageService),
        ], $request));
    }

    private function languageServiceFor(ServerRequestInterface $request): LanguageService
    {
        return $this->languageServiceFactory->create(
            $this->locales->getPreferredClientLanguage($request->getHeaderLine('Accept-Language')),
        );
    }

    private function initBackendUser(int $beUserUid): BackendUserAuthentication
    {
        $backendUser = $this->tokenAuthenticatedBackendUser->createForUid($beUserUid);
        $GLOBALS['BE_USER'] = $backendUser;

        return $backendUser;
    }

    private function validateConsentBindings(string $clientId, string $redirectUri, string $codeChallenge, string $resource, string $canonicalResource): ?ResponseInterface
    {
        if ('' === $clientId) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'client_id is required.'], 400);
        }

        if (null === $this->resolveClient($clientId, $redirectUri)) {
            return $this->invalidClientResponse();
        }

        if ('' === $codeChallenge) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'code_challenge is required (PKCE).'], 400);
        }

        if ('' === $resource || !CanonicalResource::matches($resource, $canonicalResource)) {
            return new JsonResponse(['error' => 'invalid_target', 'error_description' => 'resource parameter is missing or does not match this MCP server.'], 400);
        }

        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        $allowedClientIds = array_filter(
            array_map('trim', explode(',', (string) ($extConf['mcpAllowedClientIds'] ?? ''))),
        );
        if (!empty($allowedClientIds) && !in_array($clientId, $allowedClientIds, true)) {
            return new JsonResponse(['error' => 'unauthorized_client', 'error_description' => 'This client is not authorized to use this server.'], 400);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function handleConsent(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $consentAction = (string) ($body['consent'] ?? '');
        $state = (string) ($body['state'] ?? '');

        $ip = $this->clientIpService->resolve($request);
        $ticket = $this->oauthTicketService->verifyConsentTicket((string) ($body['consent_ticket'] ?? ''), $ip);
        if (null === $ticket) {
            $this->logger->warning('MCP OAuth: invalid or expired consent ticket', ['ip' => $ip]);

            return new JsonResponse([
                'error' => 'access_denied',
                'error_description' => 'Consent session is invalid or has expired. Please restart the authorization flow.',
            ], 403);
        }

        $beUserUid = (int) $ticket['uid'];
        $clientId = (string) $ticket['cid'];
        $redirectUri = (string) $ticket['ru'];
        $codeChallenge = (string) $ticket['cc'];
        $resource = (string) $ticket['res'];

        if ('deny' === $consentAction) {
            $separator = str_contains($redirectUri, '?') ? '&' : '?';

            return new RedirectResponse($redirectUri.$separator.'error=access_denied&state='.urlencode($state).'&iss='.urlencode(CanonicalResource::host($request)));
        }

        $backendUser = $this->tokenAuthenticatedBackendUser->createForUid($beUserUid);
        $GLOBALS['BE_USER'] = $backendUser;

        $requestedScopes = array_values(array_filter(explode(' ', (string) ($ticket['sc'] ?? ''))));
        $grantableScopes = array_values(array_intersect($requestedScopes, $this->permissionService->getAvailableScopes()));
        $grantedScopes = 'all' === $consentAction
            ? $grantableScopes
            : array_values(array_intersect((array) ($body['scopes'] ?? []), $grantableScopes));

        $workspaceUid = (int) ($body['workspace_uid'] ?? 0);
        if ($workspaceUid > 0 && false === $backendUser->checkWorkspace($workspaceUid)) {
            $workspaceUid = 0;
        }

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

        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return new RedirectResponse(
            $redirectUri.$separator.'code='.urlencode($rawCode).'&state='.urlencode($state).'&iss='.urlencode(CanonicalResource::host($request)),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateAuthorizationParams(array $params, string $canonicalResource): ?ResponseInterface
    {
        if (($params['response_type'] ?? '') !== 'code') {
            return new JsonResponse([
                'error' => 'unsupported_response_type',
                'error_description' => 'Only response_type=code is supported.',
            ], 400);
        }

        if (empty($params['client_id'])) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'client_id is required.'], 400);
        }

        if (null === $this->resolveClient((string) $params['client_id'], (string) ($params['redirect_uri'] ?? ''))) {
            $this->logger->warning('MCP OAuth: client or redirect_uri validation failed', [
                'redirect_host' => $this->redirectUriPolicy->host((string) ($params['redirect_uri'] ?? '')),
                'client_id' => $params['client_id'],
            ]);

            return $this->invalidClientResponse();
        }

        if (empty($params['code_challenge'])) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'code_challenge is required (PKCE).',
            ], 400);
        }

        if (($params['code_challenge_method'] ?? 'S256') !== 'S256') {
            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'Only code_challenge_method=S256 is supported.',
            ], 400);
        }

        // RFC 9700 §4.7: >=128 bit entropy
        $state = $params['state'] ?? '';
        if (strlen($state) < 22) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'state parameter is required and must be at least 22 characters (CSRF protection).',
            ], 400);
        }

        $resource = (string) ($params['resource'] ?? '');
        if ('' === $resource) {
            return new JsonResponse([
                'error' => 'invalid_target',
                'error_description' => sprintf(
                    'resource parameter is required (RFC 8707). Expected: %s',
                    $canonicalResource,
                ),
            ], 400);
        }
        if (!CanonicalResource::matches($resource, $canonicalResource)) {
            return new JsonResponse([
                'error' => 'invalid_target',
                'error_description' => sprintf(
                    'resource parameter does not match this MCP server. Expected: %s',
                    $canonicalResource,
                ),
            ], 400);
        }

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
     * @return null|array{client_id: string, client_name: string, redirect_uris: list<string>}
     */
    private function resolveClient(string $clientId, string $redirectUri): ?array
    {
        if ('' === $clientId || '' === $redirectUri || !$this->redirectUriPolicy->isAllowed($redirectUri)) {
            return null;
        }

        $client = $this->clientRepository->find($clientId);
        if (null === $client && $this->clientRepository->hasTokens($clientId)) {
            $this->clientRepository->register($clientId, 'MCP Client', [$redirectUri], time());
            $this->logger->notice('MCP OAuth: registered a client that predates the client registry', [
                'client_id' => $clientId,
                'redirect_host' => $this->redirectUriPolicy->host($redirectUri),
            ]);
            $client = $this->clientRepository->find($clientId);
        }

        if (null === $client || !in_array($redirectUri, $client['redirect_uris'], true)) {
            return null;
        }
        $this->clientRepository->touch($clientId, time());

        return $client;
    }

    private function isLocalhostRedirect(string $redirectUri): bool
    {
        return $this->redirectUriPolicy->isLocalhost($redirectUri);
    }

    private function clientNameFor(string $clientId): string
    {
        return $this->clientRepository->find($clientId)['client_name'] ?? $clientId;
    }

    private function invalidClientResponse(): ResponseInterface
    {
        return new JsonResponse([
            'error' => 'invalid_request',
            'error_description' => 'Unknown client_id, or a redirect_uri that this client did not register or this server does not allow (mcpAllowedRedirectUris).',
        ], 400);
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, array{key: string, label: string}>
     */
    private function getScopeDescriptions(array $scopes, LanguageService $languageService): array
    {
        $result = [];
        foreach ($scopes as $scope) {
            $label = $languageService->sL(self::LABELS.'scope.'.str_replace('mcp:', '', $scope));
            $result[$scope] = [
                'key' => $scope,
                'label' => '' !== $label ? $label : $scope,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderOAuthView(
        string $templateName,
        array $params,
        ServerRequestInterface $request,
    ): string {
        return $this->viewFactoryService->renderView(
            $templateName,
            ['EXT:ai_suite_mcp/Resources/Private/Templates/'],
            ['EXT:ai_suite_mcp/Resources/Private/Partials/'],
            ['EXT:ai_suite_mcp/Resources/Private/Layouts/'],
            array_merge($this->getBackendStyleVariables($request), ['labels' => $this->pageLabels($request)], $params, [
                'clientName' => $this->clientNameFor((string) ($params['clientId'] ?? '')),
                'redirectHost' => $this->redirectUriPolicy->host((string) ($params['redirectUri'] ?? '')),
            ]),
            $request,
        );
    }

    /**
     * @return array<string, string>
     */
    private function pageLabels(ServerRequestInterface $request): array
    {
        $languageService = $this->languageServiceFor($request);
        $labels = [];
        foreach (self::PAGE_LABELS as $key) {
            $labels[$key] = $languageService->sL(self::LABELS.'page.'.$key);
        }

        return $labels;
    }

    /**
     * @return array<string, mixed>
     */
    private function getBackendStyleVariables(ServerRequestInterface $request): array
    {
        $styles = $this->loginStyleService->resolve($request);

        return [
            'backgroundImageStyles' => $styles['backgroundImageStyles'],
            'highlightColorStyles' => $this->authStyleInfo->getHighlightColorStyles(),
            'loginLogoUrl' => $styles['logoUrl'],
            'loginLogoAlt' => $this->authStyleInfo->getLogoAlt(),
            'hasCustomLogo' => '' !== $styles['logoUrl'],
            'faviconUrl' => $styles['faviconUrl'],
            'footerNote' => $this->authStyleInfo->getFooterNote(),
            'aiSuiteIconUrl' => $this->iconService->getPublicIconUrl('tx-aisuite-extension', $request),
            'siteName' => (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'TYPO3 AI Suite'),
        ];
    }
}
