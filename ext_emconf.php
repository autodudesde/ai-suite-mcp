<?php

$EM_CONF['ai_suite_mcp'] = [
    'title' => 'AI Suite MCP',
    'description' => 'MCP (Model Context Protocol) server integration for TYPO3 and compatibility to EXT:ai_suite. Provies AI-powered tools for Claude Desktop, Claude.ai, ChatGPT, and other MCP clients.',
    'category' => 'backend',
    'author' => 'AutoDudes',
    'state' => 'beta',
    'version' => '0.3.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.11 - 14.3.99',
            'ai_suite' => '12.19.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
