<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Translation;

use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class TranslateRecordTool extends AbstractTranslateTool
{
    protected ?string $requiredScope = 'mcp:translate';

    public function getName(): string
    {
        return 'translateRecord';
    }

    public function getDescription(): string
    {
        return 'Translate one record of any language-aware TCA table, using the site glossary. '
            .'Without a model you translate the handed-back fields yourself, for free; with one the server translates '
            .DescriptionSnippets::COSTS_CREDITS.'. '
            .'Creates the translation record either way; localizeRecord only creates an empty shell.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'TCA table name'],
                'uid' => ['type' => 'integer', 'description' => 'Record UID'],
                'targetLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO target language code',
                ]),
                'model' => ['type' => 'string', 'description' => 'Optional. Omit to translate the fields yourself — the tool then prepares the translation record and hands you its fields, glossary and editorial instructions, and nothing is sent to the AI Suite Server. Name a model to have the server translate instead, which costs credits.'],
                'sourceLanguage' => $this->siteLanguages->withLanguageEnum([
                    'type' => 'string',
                    'description' => 'ISO source language. Default: site default language.',
                ]),
            ],
            'required' => ['table', 'uid', 'targetLanguage'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        return $this->translateSingleRecord(
            (string) $params['table'],
            (int) $params['uid'],
            (string) $params['targetLanguage'],
            (string) ($params['model'] ?? ''),
            (string) ($params['sourceLanguage'] ?? ''),
        );
    }
}
