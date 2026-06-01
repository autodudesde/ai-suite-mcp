<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Generate;

use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Generate a complete landing page with metadata and content elements.
 *
 * ## Ablaufplan / Implementation TODOs
 *
 * ### Phase 1: Context-Analyse
 * TODO: Bestehende Seitenstruktur analysieren (getPageTree) um Duplikate zu vermeiden
 * TODO: Bestehende Seiten zum Thema finden (searchContent) für interne Verlinkung
 * TODO: Global Instructions laden für Tonalität, Zielgruppe, Corporate Language
 * TODO: Bestehende Seitentypen/Templates ermitteln (getContentTypes) für passende CTypes
 *
 * ### Phase 2: Seite erstellen
 * TODO: Neue Seite via DataHandler anlegen (title, slug, doktype)
 * TODO: Parent-Page bestimmen (Parameter oder aus Kontext ableiten)
 * TODO: SEO-Metadata generieren (seo_title, description, og_*, twitter_*)
 *       → Kann BatchGenerateMetadataTool/WorkflowProcessingService nutzen oder inline via SendRequestService
 *
 * ### Phase 3: Content generieren
 * TODO: Content-Struktur planen (Hero, Text, CTA, etc.) basierend auf Thema + verfügbaren CTypes
 * TODO: Für jeden Content-Block: AI-Request an Server senden (createContent)
 *       → Bestehende GenerateContentTool-Logik wiederverwenden oder WorkflowProcessingService erweitern
 * TODO: Content-Elemente in korrekter Reihenfolge via DataHandler anlegen (sorting/position)
 * TODO: Interne Links zu verwandten Seiten einfügen (aus Phase 1 Kontext)
 *
 * ### Phase 4: Bild-Integration (optional)
 * TODO: Passende Bilder generieren via GenerateImageTool oder bestehende aus FAL vorschlagen
 * TODO: Bilder als sys_file_reference an Content-Elemente anhängen
 *
 * ### Phase 5: Preview & Bestätigung
 * TODO: Gesamte Seite als Preview ausgeben (previewRecord-Pattern)
 * TODO: User bestätigt → Seite wird veröffentlicht (hidden=0)
 * TODO: User kann einzelne Abschnitte ändern lassen vor Speicherung
 *
 * ### Offene Architektur-Fragen
 * - Synchron vs. Async? Für eine einzelne Seite reicht synchron.
 *   Für "erstelle 10 Landing Pages" wäre ein Batch-Ansatz nötig.
 * - Wie viel Kontext übergeben? Zu viel = teuer, zu wenig = generisch.
 *   → Bestehende Seiten als Kontext-Snippets (max 500 Zeichen pro Seite)
 * - Soll die Seitenstruktur (welche CTypes) vom AI-Model vorgeschlagen werden
 *   oder vom User vorgegeben? → Beides als Option anbieten.
 * - Image-Generierung als eigener Schritt oder integriert?
 *   → Eigener Schritt, da optional und teurer (eigenes Model nötig)
 */
// #[AutoconfigureTag('aisuite.mcp.tool')]
class GenerateLandingPageTool extends AbstractAiTool
{
    protected ?string $requiredScope = 'mcp:generate';

    public function getName(): string
    {
        return 'generateLandingPage';
    }

    public function getDescription(): string
    {
        return 'NOT YET IMPLEMENTED. Generate a complete landing page with SEO metadata and content elements. '
            .DescriptionSnippets::APPROACH_A.'Considers existing site structure to avoid duplicates. '
            .DescriptionSnippets::APPROACH_A_PREVIEW_AND_PERSIST;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'description' => 'The topic/theme for the landing page (e.g. "Vorteile von Solaranlagen für Privathaushalte").',
                ],
                'parentPageId' => [
                    'type' => 'integer',
                    'description' => 'Parent page UID where the new page should be created.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'AI model for content generation. Omit to list available models.',
                ],
                'language' => [
                    'type' => 'string',
                    'default' => 'default',
                    'description' => 'ISO language code for the generated content. Default: site default language.',
                ],
                'includeImages' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Also generate images for the page (requires image model, additional cost). Default: false.',
                ],
                'imageModel' => [
                    'type' => 'string',
                    'description' => 'AI model for image generation (e.g. GPTImage). Only used if includeImages=true.',
                ],
            ],
            'required' => ['topic', 'parentPageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        // TODO: Implement phases 1-5 as described in the class docblock.
        // This is a skeleton — the tool is registered but returns a "not yet implemented" message.

        $topic = (string) ($params['topic'] ?? '');
        $parentPageId = (int) ($params['parentPageId'] ?? 0);

        if ('' === $topic || 0 === $parentPageId) {
            return new CallToolResult(
                [new TextContent('Both "topic" and "parentPageId" are required.')],
                isError: true,
            );
        }

        $page = $this->validatePageForAi($parentPageId, Permission::PAGE_NEW);
        if ($page instanceof CallToolResult) {
            return $page;
        }

        return new CallToolResult([new TextContent(
            "## generateLandingPage — Not yet implemented\n\n"
            ."This tool is planned but not yet functional.\n\n"
            ."**Requested:**\n"
            ."- Topic: {$topic}\n"
            ."- Parent page: {$parentPageId}\n\n"
            ."**Workaround:** Use these tools manually:\n"
            ."1. `writeRecord` to create the page\n"
            ."2. `batchGenerateMetadata` or `generateMetadata` for SEO metadata\n"
            ."3. `generateContent` or `writeRecord` for content elements\n"
            ."4. `generateImage` for images (optional)\n"
        )]);
    }
}
