<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool;

final class WriteContainment
{
    public const FOLLOWS_WRITE_MODE = 'writeMode';

    public const IMMEDIATE = 'immediate';

    public const NO_CONTENT_WRITE = 'none';

    private const TOOLS = [
        self::FOLLOWS_WRITE_MODE => [
            'writeRecords', 'deleteRecords', 'copyRecords', 'moveRecords', 'localizeRecord',
            'savePageTree', 'applyTaskResults',
            'translatePage', 'translateRecord', 'translateFileMetadata',
            'patchText', 'bulkReplaceText',
            'copyMediaReference', 'replaceMediaReference',
        ],
        self::IMMEDIATE => ['uploadMedia', 'generateImage', 'moveFile'],
        self::NO_CONTENT_WRITE => [
            'generateFileMetadata',
            'batchGenerateMetadata', 'batchGenerateFileMetadata', 'batchTranslatePage', 'batchTranslateFileMetadata',
            'auditSeo', 'auditAccessibility', 'auditContent',
        ],
    ];

    public static function of(string $toolName): ?string
    {
        foreach (self::TOOLS as $containment => $tools) {
            if (in_array($toolName, $tools, true)) {
                return $containment;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function tools(string $containment): array
    {
        return self::TOOLS[$containment] ?? [];
    }
}
