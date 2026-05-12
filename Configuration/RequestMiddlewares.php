<?php

use AutoDudes\AiSuiteMcp\Mcp\Middleware\McpServerMiddleware;

return [
    'frontend' => [
        'autodudes/ai-suite-mcp/mcp-routes' => [
            'target' => McpServerMiddleware::class,
            'before' => ['typo3/cms-frontend/site'],
            'after' => ['typo3/cms-core/normalized-params-attribute'],
        ],
    ],
    'backend' => [
        'autodudes/ai-suite-mcp/mcp-routes' => [
            'target' => McpServerMiddleware::class,
            'before' => ['typo3/cms-backend/site-resolver'],
            'after' => ['typo3/cms-core/normalized-params-attribute'],
        ],
    ],
];
