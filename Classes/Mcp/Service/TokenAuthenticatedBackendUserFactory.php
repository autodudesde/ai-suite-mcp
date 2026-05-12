<?php

declare(strict_types=1);

/*
 *
 * This file is part of the "ai_suite_mcp" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *
 */

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds a TYPO3 `BackendUserAuthentication` instance for an MCP token request.
 *
 * Token-based MCP requests bypass the normal `BackendUserAuthenticator` middleware,
 * so neither `start()` nor a real session manager run for them. Without a session
 * object on the BE_USER, anything that resolves form data via `FormDataCompiler`
 * crashes inside `Clipboard::initializeClipboard()` → `getModuleData()` →
 * `$userSession->getIdentifier()` ("Call to a member function getIdentifier() on null").
 * Attaching an anonymous session via `initializeUserSessionManager()` gives the BE_USER
 * a valid identifier without persisting anything.
 */
class TokenAuthenticatedBackendUserFactory implements SingletonInterface
{
    /**
     * Resolve a BE user by UID and return a fully-bootstrapped `BackendUserAuthentication`.
     * Caller is responsible for assigning to `$GLOBALS['BE_USER']`, setting up `Context`
     * aspects, workspace, etc. — those are call-site-specific.
     */
    public function createForUid(int $beUserUid): BackendUserAuthentication
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid($beUserUid);
        $backendUser->fetchGroupData();
        $backendUser->initializeUserSessionManager();

        return $backendUser;
    }
}
