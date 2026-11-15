<?php

declare(strict_types=1);

use AutoDudes\AiSuiteMcp\Compat\ClassicModeBundle;
use AutoDudes\AiSuiteMcp\Mcp\Hooks\PasswordChangeHook;
use AutoDudes\AiSuiteMcp\Mcp\Log\SensitiveDataProcessor;
use Mcp\Server\Server;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') || exit('Access denied.');

ClassicModeBundle::register(ExtensionManagementUtility::extPath('ai_suite_mcp'), Server::class);

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['ai_suite_mcp']
    = PasswordChangeHook::class;

try {
    $aisuiteMcpExtConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('ai_suite_mcp');
} catch (Throwable) {
    $aisuiteMcpExtConf = [];
}

$aisuiteMcpAdditionalRedactionPatterns = array_values(array_filter(
    array_map('trim', explode(',', (string) ($aisuiteMcpExtConf['mcpLogRedactionPatterns'] ?? ''))),
    static fn (string $entry): bool => '' !== $entry,
));

$GLOBALS['TYPO3_CONF_VARS']['LOG']['AutoDudes']['AiSuiteMcp']['writerConfiguration'] = [
    LogLevel::WARNING => [
        FileWriter::class => [
            'logFile' => Environment::getVarPath().'/log/aisuite_mcp_warnings.log',
        ],
    ],
    LogLevel::INFO => [
        FileWriter::class => [
            'logFile' => Environment::getVarPath().'/log/aisuite_mcp.log',
            'disabled' => !(bool) ($aisuiteMcpExtConf['mcpLogVerbose'] ?? false),
        ],
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['LOG']['AutoDudes']['AiSuiteMcp']['processorConfiguration'] = [
    LogLevel::DEBUG => [
        SensitiveDataProcessor::class => [
            'additionalPatterns' => $aisuiteMcpAdditionalRedactionPatterns,
        ],
    ],
];

unset($aisuiteMcpExtConf, $aisuiteMcpAdditionalRedactionPatterns);
