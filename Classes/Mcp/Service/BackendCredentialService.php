<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\RateLimitPolicy;
use AutoDudes\AiSuiteMcp\Mcp\Exception\RateLimitExceededException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Event\LoginAttemptFailedEvent;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\SysLog\Action\Login as SystemLogLoginAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class BackendCredentialService
{
    private ?string $dummyHash = null;

    public function __construct(
        private readonly PasswordHashFactory $passwordHashFactory,
        private readonly RateLimiterService $rateLimiter,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function authenticate(string $username, string $password, ServerRequestInterface $request): ?int
    {
        if ('' === $username || '' === $password) {
            return null;
        }

        try {
            $this->rateLimiter->assertNotLocked($this->limiterKey($username));
        } catch (RateLimitExceededException $e) {
            $this->logger->warning('MCP OAuth: login attempts for one account exceeded the limit', ['reason' => $e->getMessage()]);

            return null;
        }

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        try {
            $backendUser->setBeUserByName($username);
            $user = $backendUser->user;

            if (null === $user) {
                $this->passwordHashFactory->getDefaultHashInstance('BE')->checkPassword($password, $this->dummyHash());
                $this->reportFailure($backendUser, $request, $username, 'user not found');

                return null;
            }

            $hashInstance = $this->passwordHashFactory->get($user['password'], 'BE');
            if (!$hashInstance->checkPassword($password, $user['password'])) {
                $this->reportFailure($backendUser, $request, $username, 'invalid password');

                return null;
            }

            if (!$backendUser->isUserAllowedToLogin()) {
                $this->logger->notice('Authentication refused: the backend is locked for this user (BE.adminOnly)', ['be_user_uid' => (int) $user['uid']]);

                return null;
            }

            return (int) $user['uid'];
        } catch (InvalidPasswordHashException $e) {
            $this->reportFailure($backendUser, $request, $username, 'password hash error');
        } catch (\Throwable $e) {
            $this->logger->notice('Authentication failed', ['username' => $username, 'error' => $e->getMessage()]);
        }

        return null;
    }

    public function hasMcpAccess(int $beUserUid): bool
    {
        try {
            $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $backendUser->setBeUserByUid($beUserUid);
            $backendUser->fetchGroupData();

            return $backendUser->check('custom_options', 'tx_aisuite_features:enable_mcp_access');
        } catch (\Throwable $e) {
            $this->logger->warning('MCP OAuth: access check failed for backend user, denying access', [
                'beUserUid' => $beUserUid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function limiterKey(string $username): string
    {
        return 'oauth_login_'.hash('sha256', mb_strtolower($username));
    }

    private function reportFailure(BackendUserAuthentication $backendUser, ServerRequestInterface $request, string $username, string $reason): void
    {
        try {
            $this->rateLimiter->checkAndIncrement($this->limiterKey($username), RateLimitPolicy::oauthLogin());
        } catch (RateLimitExceededException) {
            // The failure that crosses the limit sets the lock; refusing it is already the caller's answer.
        }

        $this->logger->notice('Authentication failed: '.$reason, ['username' => $username]);
        $backendUser->writelog(
            SystemLogType::LOGIN,
            SystemLogLoginAction::ATTEMPT,
            SystemLogErrorClassification::SECURITY_NOTICE,
            1, // @phpstan-ignore argument.type (v12 stores it in a NOT NULL column, v14 ignores it)
            'MCP OAuth login-attempt from ###IP###, username \'%s\' not accepted',
            [$username],
        );
        $this->eventDispatcher->dispatch(new LoginAttemptFailedEvent($backendUser, $request, ['uname' => $username, 'status' => 'login']));
    }

    private function dummyHash(): string
    {
        return $this->dummyHash ??= (string) $this->passwordHashFactory->getDefaultHashInstance('BE')->getHashedPassword(bin2hex(random_bytes(16)));
    }
}
