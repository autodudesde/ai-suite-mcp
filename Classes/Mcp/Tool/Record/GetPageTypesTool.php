<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetPageTypesTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;

    public function getName(): string
    {
        return 'getPageTypes';
    }

    public function getDescription(): string
    {
        return 'List available page types (doktypes) the current user can create.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $ignored = [3, 4, 6, 7, 199, 254, 255];
        $beUser = $this->getBackendUser();
        $result = [];

        // Read doktype options from TCA via the same path GetContentTypesTool uses for CType
        // — doktype is a TCA `select` field with `items`, no need for a sub-schema enumeration
        // helper on the service.
        $items = $this->tcaCompatibilityService->getFieldConfiguration('pages', 'doktype')['items'] ?? [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['value']) || '--div--' === $item['value'] || '' === $item['value']) {
                continue;
            }

            $doktype = (int) $item['value'];
            if (\in_array($doktype, $ignored, true)) {
                continue;
            }
            if (null !== $beUser && !$beUser->isAdmin() && !$beUser->check('pagetypes_select', (string) $doktype)) {
                continue;
            }

            $label = $this->resolveLabel((string) ($item['label'] ?? $doktype));
            $result[] = ['doktype' => $doktype, 'label' => $label ?: (string) $doktype];
        }

        $text = "Available page types:\n\n";
        foreach ($result as $t) {
            $text .= sprintf("- **%s** (doktype: %d)\n", $t['label'], $t['doktype']);
        }

        return new CallToolResult([new TextContent($text)]);
    }
}
