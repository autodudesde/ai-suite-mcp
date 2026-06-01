<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Service\DataHandlerSanitizerService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;

abstract class AbstractDataTool extends AbstractTool
{
    protected readonly DataHandlerSanitizerService $dataHandlerSanitizer;

    public function __construct(ToolContext $mcpToolContext)
    {
        parent::__construct($mcpToolContext);
        $this->dataHandlerSanitizer = $mcpToolContext->dataHandlerSanitizer;
    }
}
