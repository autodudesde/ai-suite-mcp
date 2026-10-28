<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\FormEngine;

use TYPO3\CMS\Backend\Form\FormDataGroup\OrderedProviderList;
use TYPO3\CMS\Backend\Form\FormDataGroupInterface;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseUserPermissionCheck;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class TcaDatabaseRecordForReading implements FormDataGroupInterface
{
    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public function compile(array $result): array
    {
        $providers = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'];
        // Edit permission only: the caller has asserted read access before compiling.
        $providers[DatabaseUserPermissionCheck::class]['disabled'] = true;

        $providerList = GeneralUtility::makeInstance(OrderedProviderList::class);
        $providerList->setProviderList($providers);

        return $providerList->compile($result);
    }
}
