<?php

declare(strict_types=1);

use AutoDudes\AiSuiteMcp\Controller\McpController;

return [
    'aisuite_mcp_create_token' => [
        'path' => '/mcp/create-token',
        'target' => McpController::class.'::createTokenAction',
    ],
    'aisuite_mcp_revoke_token' => [
        'path' => '/mcp/revoke-token',
        'target' => McpController::class.'::revokeTokenAction',
    ],
];
