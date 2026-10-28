<?php

declare(strict_types=1);

use AutoDudes\AiSuiteMcp\Controller\McpController;

return [
    'ai_suite_mcp' => [
        'path' => '/aisuite/mcp',
        'target' => McpController::class.'::handleRequest',
    ],
];
