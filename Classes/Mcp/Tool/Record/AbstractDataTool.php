<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\DataHandlerSanitizer;

/**
 * Base class for record-based MCP tools that need XSS sanitization via DataHandler.
 *
 * Table/field validation, permission checks, and label resolution are in AbstractTool.
 */
abstract class AbstractDataTool extends AbstractTool
{
    protected readonly DataHandlerSanitizer $dataHandlerSanitizer;

    public function __construct(McpToolContext $mcpToolContext)
    {
        parent::__construct($mcpToolContext);
        $this->dataHandlerSanitizer = $mcpToolContext->dataHandlerSanitizer;
    }
}
