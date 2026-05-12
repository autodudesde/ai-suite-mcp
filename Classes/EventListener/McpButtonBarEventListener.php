<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\EventListener;

use AutoDudes\AiSuite\Events\AfterButtonBarGeneratedEvent;
use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\IconService;
use AutoDudes\AiSuite\Template\Components\Buttons\AiSuiteLinkButton;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class McpButtonBarEventListener
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly UriBuilder $uriBuilder,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly IconService $iconService,
    ) {}

    public function __invoke(AfterButtonBarGeneratedEvent $event): void
    {
        if (!$this->backendUserService->checkPermissions('tx_aisuite_features:enable_mcp_access')) {
            return;
        }

        $request = $event->getRequest();
        $site = $request->getAttribute('site');
        $rootPageId = $site?->getRootPageId() ?? 0;
        $uriParameters = [
            'id' => $request->getQueryParams()['id'] ?? $rootPageId,
        ];

        $url = (string) $this->uriBuilder->buildUriFromRoute('ai_suite_mcp', $uriParameters);

        $languageService = $this->languageServiceFactory->createFromUserPreferences($this->backendUserService->getBackendUser());
        $label = $languageService->sL('LLL:EXT:ai_suite_mcp/Resources/Private/Language/locallang_module.xlf:aiSuite.module.actionmenu.mcp') ?: 'MCP';

        $button = GeneralUtility::makeInstance(AiSuiteLinkButton::class);
        $button
            ->setIcon($this->iconService->getIcon('actions-link'))
            ->setTitle($label)
            ->setShowLabelText(true)
            ->setClasses('btn-md btn-default rounded')
            ->setHref($url)
        ;

        $event->getButtonBar()->addButton($button);
    }
}
