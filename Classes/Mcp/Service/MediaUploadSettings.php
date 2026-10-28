<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class MediaUploadSettings
{
    /**
     * @var list<string>
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @return array{defaultFolder: string, maxBytes: int, allowedExtensions: list<string>, allowUrlFetch: bool, hostDenylist: list<string>}
     */
    public function read(): array
    {
        $maxSizeMb = (int) $this->readConfig('mcpMediaMaxSizeMb', '50');
        $allowed = array_values(array_filter(array_map(
            static fn (string $e): string => strtolower(trim($e)),
            explode(',', $this->readConfig('mcpMediaAllowedExtensions', 'jpg,jpeg,png,gif,webp,avif,mp4,webm,ogg')),
        )));
        $denylist = array_values(array_filter(array_map(
            'trim',
            explode(',', $this->readConfig('mcpMediaHostDenylist', '')),
        )));

        return [
            'defaultFolder' => $this->readConfig('mcpMediaDefaultFolder', '1:/user_upload/'),
            'maxBytes' => $maxSizeMb > 0 ? $maxSizeMb * 1024 * 1024 : 0,
            'allowedExtensions' => $allowed,
            'allowUrlFetch' => '0' !== $this->readConfig('mcpMediaAllowUrlFetch', '1'),
            'hostDenylist' => $denylist,
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedImageExtensions(): array
    {
        return array_values(array_intersect(self::IMAGE_EXTENSIONS, $this->read()['allowedExtensions']));
    }

    private function readConfig(string $key, string $default): string
    {
        try {
            $value = $this->extensionConfiguration->get('ai_suite_mcp', $key);
        } catch (\Throwable) {
            return $default;
        }

        if (null === $value || '' === $value || is_array($value)) {
            return $default;
        }

        return (string) $value;
    }
}
