<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

class RenderedHtmlTextExtractor
{
    public function extract(string $html): string
    {
        $html = preg_replace('/<(script|style|nav|footer)\b[^>]*>.*?<\/\1>/si', '', $html) ?? $html;

        if (preg_match('/<main\b[^>]*>(.*?)<\/main>/si', $html, $matches)) {
            $html = $matches[1];
        } elseif (preg_match('/<article\b[^>]*>(.*?)<\/article>/si', $html, $matches)) {
            $html = $matches[1];
        } else {
            $html = preg_replace('/<header\b[^>]*>.*?<\/header>/si', '', $html) ?? $html;
            if (preg_match('/<div[^>]*(?:id|class)\s*=\s*["\'][^"\']*(?:content|main)[^"\']*["\'][^>]*>(.*?)<\/div>/si', $html, $matches)) {
                $html = $matches[1];
            }
        }

        $text = strip_tags($html);

        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
