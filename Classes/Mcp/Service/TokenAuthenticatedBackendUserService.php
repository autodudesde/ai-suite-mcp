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

class TokenAuthenticatedBackendUserService implements SingletonInterface
{
    public function createForUid(int $beUserUid): BackendUserAuthentication
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid($beUserUid);
        $backendUser->fetchGroupData();
        $backendUser->initializeUserSessionManager();

        return $backendUser;
    }
}
