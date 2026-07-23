<?php

defined('TYPO3') or exit;

$lll = 'LLL:EXT:ai_suite_mcp/Resources/Private/Language/locallang_tca.xlf:';

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_features']['items']['enable_mcp_access'] = [
    $lll.'aiSuite.mcp.permissions.enableMcpAccess',
    'tx-aisuite-permissions',
    $lll.'aiSuite.mcp.permissions.enableMcpAccessDescription',
];

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_features']['items']['enable_mcp_media_upload'] = [
    $lll.'aiSuite.mcp.permissions.enableMcpMediaUpload',
    'tx-aisuite-permissions',
    $lll.'aiSuite.mcp.permissions.enableMcpMediaUploadDescription',
];

// readRenderedPage renders through a backend preview session of the MCP user, so it also returns
// hidden and unpublished pages and workspace drafts. That is more than the other mcp:read tools do,
// hence its own flag on top of the scope.
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_features']['items']['enable_mcp_rendered_page_read'] = [
    $lll.'aiSuite.mcp.permissions.enableMcpRenderedPageRead',
    'tx-aisuite-permissions',
    $lll.'aiSuite.mcp.permissions.enableMcpRenderedPageReadDescription',
];
