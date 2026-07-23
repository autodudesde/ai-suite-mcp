<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ListPageTypesTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;
    protected bool $readOnlyHint = true;

    public function getName(): string
    {
        return 'listPageTypes';
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

            $label = $this->tcaLabel->resolveLabel((string) ($item['label'] ?? $doktype));
            $result[] = ['doktype' => $doktype, 'label' => $label ?: (string) $doktype];
        }

        $text = "Available page types:\n\n";
        foreach ($result as $t) {
            $text .= sprintf("- **%s** (doktype: %d)\n", $t['label'], $t['doktype']);
        }

        return $this->textResult($text);
    }
}
