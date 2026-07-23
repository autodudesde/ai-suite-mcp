<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\DataHandlerErrorFormatter;
use AutoDudes\AiSuiteMcp\Mcp\Service\DataHandlerSanitizerService;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpExcludedTablesService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OutputFormatterService;
use AutoDudes\AiSuiteMcp\Mcp\Service\ParameterValidatorService;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordAccessService;
use AutoDudes\AiSuiteMcp\Mcp\Service\SessionTrackerService;
use AutoDudes\AiSuiteMcp\Mcp\Service\SiteLanguageService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TcaLabelService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

class ToolContext implements SingletonInterface
{
    public function __construct(
        public readonly McpUserContext $userContext,
        public readonly PermissionService $permissionService,
        public readonly LoggerInterface $logger,
        public readonly TcaCompatibilityService $tcaCompatibilityService,
        public readonly SiteFinder $siteFinder,
        public readonly LocalizationService $localizationService,
        public readonly BackendUserService $backendUserService,
        public readonly SendRequestService $sendRequestService,
        public readonly SessionTrackerService $creditTracker,
        public readonly ExtensionConfiguration $extensionConfiguration,
        public readonly Context $typo3Context,
        public readonly DataHandlerSanitizerService $dataHandlerSanitizer,
        public readonly DataHandlerErrorFormatter $dataHandlerError,
        public readonly ResourceFactory $resourceFactory,
        public readonly McpExcludedTablesService $excludedTablesService,
        public readonly RecordAccessService $recordAccess,
        public readonly TcaLabelService $tcaLabel,
        public readonly OutputFormatterService $outputFormatter,
        public readonly ParameterValidatorService $parameterValidator,
        public readonly SiteLanguageService $siteLanguages,
    ) {}
}
