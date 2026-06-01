<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\OperatingGuidelines;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetOperatingGuidelinesTool extends AbstractTool
{
    protected ?string $requiredScope = null;

    public function __construct(ToolContext $mcpToolContext)
    {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'getOperatingGuidelines';
    }

    public function getDescription(): string
    {
        return 'REQUIRED FIRST STEP — call before any other tool in every new session. '
            .'Returns mandatory workflow rules and available sites with their languages. '
            .'Do not proceed with any task until called successfully.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $text = OperatingGuidelines::get();

        $sites = $this->siteFinder->getAllSites();
        if (!empty($sites)) {
            $text .= "\n\n## Available sites\n";
            foreach ($sites as $site) {
                $languages = [];
                foreach ($site->getAllLanguages() as $lang) {
                    $label = $lang->getLocale()->getLanguageCode();
                    if (0 === $lang->getLanguageId()) {
                        $label .= ' (default)';
                    }
                    $languages[] = $label;
                }
                $text .= sprintf(
                    "- %s (root: %d) — %s\n",
                    $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                    $site->getRootPageId(),
                    implode(', ', $languages),
                );
            }
        }

        return $this->textResult($text);
    }
}
