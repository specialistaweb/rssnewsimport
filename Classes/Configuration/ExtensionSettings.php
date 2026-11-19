<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Typed access (with safety limits) to the extension configuration.
 */
final class ExtensionSettings
{
    /** @var array<string, mixed> */
    private readonly array $settings;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        try {
            $settings = $extensionConfiguration->get('rssnewsimport');
        } catch (\Throwable) {
            $settings = [];
        }
        if (!is_array($settings) || $settings === []) {
            // Configuration saved before 3.0.0, when the extension key was "rss_news_import"
            $legacy = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['rss_news_import'] ?? [];
            $settings = is_array($legacy) ? $legacy : [];
        }
        $this->settings = is_array($settings) ? $settings : [];
    }

    public function getImageFolder(): string
    {
        $folder = trim((string)($this->settings['imageFolder'] ?? ''));
        return $folder !== '' ? rtrim($folder, '/') . '/' : '1:/user_upload/rssnewsimport/';
    }

    public function getHttpTimeout(): int
    {
        return $this->int('httpTimeout', 15, 2, 120);
    }

    public function getUserAgent(): string
    {
        $ua = trim((string)($this->settings['userAgent'] ?? ''));
        // Nessun a capo: evita header injection
        $ua = (string)preg_replace('/[\r\n]+/', ' ', $ua);
        return $ua !== '' ? mb_substr($ua, 0, 200) : 'TYPO3 RSS News Import/1.1';
    }

    public function getTeaserLength(): int
    {
        return $this->int('teaserLength', 300, 0, 5000);
    }

    public function getAllowedTags(): string
    {
        $tags = trim((string)($this->settings['allowedTags'] ?? ''));
        return $tags !== '' ? $tags : '<p><br><a><strong><b><em><i><u><ul><ol><li><blockquote><h2><h3><h4><h5><h6><figure><figcaption><img><table><thead><tbody><tr><th><td><code><pre>';
    }

    public function getMaxFeedSizeBytes(): int
    {
        return $this->int('maxFeedSizeKb', 5120, 16, 51200) * 1024;
    }

    public function getMaxItemsPerFeed(): int
    {
        return $this->int('maxItemsPerFeed', 200, 1, 2000);
    }

    public function getMaxImageSizeBytes(): int
    {
        return $this->int('maxImageSizeKb', 5120, 16, 51200) * 1024;
    }

    public function getMaxImagePixels(): int
    {
        return $this->int('maxImageMegapixels', 40, 1, 200) * 1_000_000;
    }

    public function getMaxImagesPerRun(): int
    {
        return $this->int('maxImagesPerRun', 50, 0, 2000);
    }

    public function getMaxBodySizeBytes(): int
    {
        return $this->int('maxBodySizeKb', 200, 4, 2048) * 1024;
    }

    public function allowPrivateHosts(): bool
    {
        return (bool)($this->settings['allowPrivateHosts'] ?? false);
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $value = $this->settings[$key] ?? $default;
        $value = is_numeric($value) ? (int)$value : $default;
        return max($min, min($max, $value));
    }
}
