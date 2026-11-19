<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Feed;

use SpecialistaWeb\RssNewsImport\Configuration\ExtensionSettings;
use SpecialistaWeb\RssNewsImport\Domain\Dto\FeedItem;
use SpecialistaWeb\RssNewsImport\Domain\Dto\FeedReadResult;
use SpecialistaWeb\RssNewsImport\Http\SimplePieHttpClient;
use SpecialistaWeb\RssNewsImport\Security\UrlGuard;
use SpecialistaWeb\RssNewsImport\Service\HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use SimplePie\Item;
use SimplePie\SimplePie;

/**
 * Reads a feed with SimplePie (https://simplepie.org) and converts it into FeedItem objects.
 *
 * SimplePie takes care of: requesting the feed, detecting the format (RSS 0.9x/1.0/2.0, Atom 0.3/1.0,
 * Media RSS, iTunes, Dublin Core), encoding conversion, dates, authors, categories, enclosures,
 * resolving relative URLs and a first sanitisation of the HTML.
 *
 * The transport is the PSR-18 client SimplePieHttpClient (TYPO3 HTTP stack + protections).
 * Security choices:
 * - SimplePie cache disabled: the state (ETag, Last-Modified, fingerprint) is stored in the feed record
 * - autodiscovery disabled: a "file://" link in an HTML page would be read by SimplePie
 *   from the local disk; besides, every attempt would cost extra HTTP requests
 */
final class FeedReader
{
    /** Tags removed by SimplePie together with their content (in addition to its defaults) */
    private const STRIP_TAGS = [
        'base', 'blink', 'body', 'doctype', 'embed', 'font', 'form', 'frame', 'frameset', 'html', 'iframe',
        'input', 'marquee', 'meta', 'noscript', 'object', 'param', 'script', 'style', 'svg', 'math',
        'template', 'link', 'applet', 'canvas', 'audio', 'video', 'source', 'track', 'button', 'select', 'textarea',
    ];

    private const STRIP_ATTRIBUTES = [
        'bgsound', 'expr', 'id', 'class', 'style', 'srcset', 'sizes', 'lowsrc', 'dynsrc', 'background',
        'onabort', 'onblur', 'onchange', 'onclick', 'ondblclick', 'onerror', 'onfinish', 'onfocus', 'oninput',
        'onkeydown', 'onkeypress', 'onkeyup', 'onload', 'onmousedown', 'onmousemove', 'onmouseout',
        'onmouseover', 'onmouseup', 'onpointerdown', 'onpointerup', 'onreset', 'onresize', 'onscroll',
        'onselect', 'onsubmit', 'ontoggle', 'onunload', 'onanimationstart', 'ontransitionend',
    ];

    private ?SimplePieHttpClient $httpClient = null;

    public function __construct(
        private readonly HttpClient $transport,
        private readonly UrlGuard $urlGuard,
        private readonly ExtensionSettings $settings,
    ) {}

    /**
     * @param array<string, string> $conditionalHeaders If-None-Match / If-Modified-Since
     * @param string $knownHash content fingerprint of the last successful import ('' = always process)
     * @throws \RuntimeException
     */
    public function read(string $url, array $conditionalHeaders = [], string $knownHash = ''): FeedReadResult
    {
        // Validate before handing the URL to SimplePie: SimplePie would read a local path
        // (e.g. "/etc/passwd") directly from disk without going through the HTTP client
        $this->urlGuard->check($url);

        $client = $this->getHttpClient();
        $client->prepare($url, $conditionalHeaders, $knownHash);

        $pie = $this->createSimplePie($url);
        $warnings = [];
        // SimplePie writes some warnings directly with error_log() if error_reporting includes them
        $previousReporting = error_reporting(error_reporting() & ~(E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING | E_DEPRECATED | E_USER_DEPRECATED));
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            // SimplePie warnings and deprecations must not stop the import
            $warnings[] = $message;
            return true;
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $ok = $pie->init();

            if ($client->isNotModified() || $client->isUnchanged()) {
                return new FeedReadResult(
                    notModified: true,
                    etag: $client->getEtag(),
                    lastModified: $client->getLastModified(),
                    contentHash: $client->getBodyHash() ?: $knownHash,
                );
            }
            if (!$ok || $pie->error()) {
                $error = $pie->error();
                $error = is_array($error) ? implode('; ', $error) : (string)$error;
                throw new \RuntimeException($error !== '' ? $this->shorten($error) : 'Feed not readable.', 1731700010);
            }

            $items = [];
            foreach ($pie->get_items(0, $this->settings->getMaxItemsPerFeed()) as $item) {
                $items[] = $this->mapItem($item);
            }

            return new FeedReadResult(
                notModified: false,
                items: $items,
                etag: $client->getEtag(),
                lastModified: $client->getLastModified(),
                contentHash: $client->getBodyHash(),
            );
        } finally {
            restore_error_handler();
            error_reporting($previousReporting);
            unset($pie);
        }
    }

    private function createSimplePie(string $url): SimplePie
    {
        $factory = new HttpFactory();
        $pie = new SimplePie();
        $pie->set_http_client($this->getHttpClient(), $factory, $factory);
        $pie->enable_cache(false);
        $pie->set_autodiscovery_level(SimplePie::LOCATOR_NONE);
        $pie->set_max_checked_feeds(0);
        $pie->enable_order_by_date(true);
        $pie->set_output_encoding('UTF-8');
        $pie->strip_htmltags(self::STRIP_TAGS);
        $pie->strip_attributes(self::STRIP_ATTRIBUTES);
        $pie->strip_comments(true);
        $pie->set_feed_url($url);
        return $pie;
    }

    private function getHttpClient(): SimplePieHttpClient
    {
        return $this->httpClient ??= new SimplePieHttpClient($this->transport, $this->settings->getMaxFeedSizeBytes());
    }

    private function mapItem(Item $item): FeedItem
    {
        $author = $item->get_author();
        [$authorName, $authorEmail] = $this->splitAuthor(
            $author !== null ? $this->text((string)($author->get_name() ?? '')) : '',
            $author !== null ? $this->text((string)($author->get_email() ?? '')) : ''
        );
        $date = $item->get_date('U');

        $categories = [];
        foreach ($item->get_categories() ?? [] as $category) {
            $label = $category->get_label() ?? $category->get_term() ?? '';
            if ($label !== '') {
                $categories[] = $this->text($label);
            }
        }

        $content = (string)($item->get_content(true) ?? '');
        $description = (string)($item->get_description(true) ?? '');

        return new FeedItem(
            guid: $this->text((string)($item->get_id(false, false) ?? '')),
            title: $this->text((string)($item->get_title() ?? '')),
            link: $this->httpUrl((string)($item->get_permalink() ?? '')),
            description: $description,
            content: $content,
            publishedAt: is_numeric($date) ? (new \DateTimeImmutable())->setTimestamp((int)$date) : null,
            author: $authorName,
            authorEmail: $authorEmail,
            imageUrl: $this->findImage($item, $content . $description),
            categories: array_values(array_unique(array_filter($categories))),
        );
    }

    /**
     * Image: widest image enclosure (enclosure / media:content), then media:thumbnail,
     * then the thumbnails of the enclosures, finally the first <img> of the text.
     */
    private function findImage(Item $item, string $html): string
    {
        $best = '';
        $bestWidth = -1;
        $thumbnails = [];
        foreach ($item->get_enclosures() ?? [] as $enclosure) {
            $link = $this->httpUrl((string)($enclosure->get_link() ?? ''));
            $type = strtolower((string)($enclosure->get_type() ?? ''));
            $medium = strtolower((string)($enclosure->get_medium() ?? ''));
            if ($link !== '' && ($medium === 'image' || str_starts_with($type, 'image/') || ($type === '' && $medium === '' && $this->looksLikeImage($link)))) {
                $width = (int)($enclosure->get_width() ?? 0);
                if ($width > $bestWidth) {
                    $best = $link;
                    $bestWidth = $width;
                }
            }
            foreach ($enclosure->get_thumbnails() ?? [] as $thumbnail) {
                $thumbnails[] = (string)$thumbnail;
            }
        }
        if ($best !== '') {
            return $best;
        }

        $thumbnail = $item->get_thumbnail();
        if (is_array($thumbnail) && ($url = $this->httpUrl((string)($thumbnail['url'] ?? ''))) !== '') {
            return $url;
        }
        foreach ($thumbnails as $candidate) {
            if (($url = $this->httpUrl($candidate)) !== '') {
                return $url;
            }
        }
        if ($html !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            return $this->httpUrl($m[1]);
        }
        return '';
    }

    /**
     * RSS 2.0 <author> is often "email (Name)": SimplePie returns it whole as email.
     *
     * @return array{0: string, 1: string}
     */
    private function splitAuthor(string $name, string $email): array
    {
        if ($email !== '' && preg_match('/^\s*([^\s()]+@[^\s()]+)\s*\(([^)]+)\)\s*$/', $email, $m)) {
            return [$name !== '' ? $name : trim($m[2]), trim($m[1])];
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [$name !== '' ? $name : $email, ''];
        }
        return [$name, $email];
    }

    private function looksLikeImage(string $url): bool
    {
        return (bool)preg_match('/\.(jpe?g|png|gif|webp|avif)$/', strtolower((string)parse_url($url, PHP_URL_PATH)));
    }

    /**
     * Absolute http(s) URLs only: blocks "javascript:", "data:" and the like.
     */
    private function httpUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20]/', $url)) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        return in_array(strtolower($parts['scheme']), ['http', 'https'], true) ? $url : '';
    }

    /**
     * SimplePie returns texts with HTML entities: they are turned back into plain text.
     */
    private function text(string $value): string
    {
        $value = strip_tags(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    private function shorten(string $message): string
    {
        return mb_substr(trim(strtok($message, "\n") ?: $message), 0, 300);
    }
}
