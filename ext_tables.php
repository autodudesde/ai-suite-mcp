<?php

defined('TYPO3') or exit;

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_features']['items']['enable_mcp_access'] = [
    'Enable MCP Access',
    'tx-aisuite-permissions',
    'Allows connecting via MCP protocol (Claude Desktop, Claude.ai, ChatGPT, etc.)',
];

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_features']['items']['enable_mcp_media_upload'] = [
    'Enable MCP Media Upload',
    'tx-aisuite-permissions',
    'Allows uploading images/videos into FAL via MCP (by URL, base64 upload, or YouTube/Vimeo link). Filemount permissions still apply on top.',
];
