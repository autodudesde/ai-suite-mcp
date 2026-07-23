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
        return 'Translate one record of any language-aware TCA table into a target language, using DeepL and the site glossary '
            .DescriptionSnippets::COSTS_CREDITS.'. '
            .'Writes the translation itself; localizeRecord only creates an empty translation shell.';
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
                'model' => ['type' => 'string', 'description' => 'Translation model identifier. Omit to get a list of available models first.'],
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
        $model = (string) ($params['model'] ?? '');

        if ('' === $model) {
            return $this->listTranslationModels();
        }

        return $this->translateSingleRecord(
            (string) $params['table'],
            (int) $params['uid'],
            (string) $params['targetLanguage'],
            $model,
            (string) ($params['sourceLanguage'] ?? ''),
        );
    }
}
