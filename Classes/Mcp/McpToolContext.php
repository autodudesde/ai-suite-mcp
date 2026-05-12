<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Service\DataHandlerSanitizer;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpExcludedTablesService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

class McpToolContext implements SingletonInterface
{
    public function __construct(
        public readonly McpUserContext $userContext,
        public readonly McpPermissionService $permissionService,
        public readonly LoggerInterface $logger,
        public readonly TcaCompatibilityService $tcaCompatibilityService,
        public readonly SiteFinder $siteFinder,
        public readonly LocalizationService $localizationService,
        public readonly BackendUserService $backendUserService,
        public readonly SendRequestService $sendRequestService,
        public readonly McpSessionCreditTracker $creditTracker,
        public readonly ExtensionConfiguration $extensionConfiguration,
        public readonly Context $typo3Context,
        public readonly DataHandlerSanitizer $dataHandlerSanitizer,
        public readonly ResourceFactory $resourceFactory,
        public readonly McpExcludedTablesService $excludedTablesService,
    ) {}
}
