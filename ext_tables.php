<?php

defined('TYPO3') or exit;

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_aisuite_features']['items']['enable_mcp_access'] = [
    'Enable MCP Access',
    'tx-aisuite-permissions',
    'Allows connecting via MCP protocol (Claude Desktop, Claude.ai, ChatGPT, etc.)',
];
