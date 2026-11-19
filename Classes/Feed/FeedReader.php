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
 * Legge un feed con SimplePie (https://simplepie.org) e lo converte in oggetti FeedItem.
 *
 * SimplePie si occupa di: richiesta del feed, rilevamento del formato (RSS 0.9x/1.0/2.0, Atom 0.3/1.0,
 * Media RSS, iTunes, Dublin Core), conversione di codifica, date, autori, categorie, allegati,
 * risoluzione degli URL relativi e prima sanitizzazione dell'HTML.
 *
 * Il trasporto è il client PSR-18 SimplePieHttpClient (stack HTTP di TYPO3 + protezioni).
 * Scelte di sicurezza:
 * - cache di SimplePie disattivata: lo stato (ETag, Last-Modified, impronta) è nel record del feed
 * - autodiscovery disattivato: un link "file://" in una pagina HTML verrebbe letto da SimplePie
 *   dal disco locale; inoltre ogni tentativo costerebbe richieste HTTP in più
 */
final class FeedReader
{
    /** Tag rimossi da SimplePie insieme al contenuto (oltre ai suoi predefiniti) */
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
     * @param string $knownHash impronta del contenuto dell'ultima importazione riuscita ('' = sempre elaborare)
     * @throws \RuntimeException
     */
    public function read(string $url, array $conditionalHeaders = [], string $knownHash = ''): FeedReadResult
    {
        // Validazione prima di passare l'URL a SimplePie: SimplePie leggerebbe un percorso locale
        // (es. "/etc/passwd") direttamente dal disco senza passare dal client HTTP
        $this->urlGuard->check($url);

        $client = $this->getHttpClient();
        $client->prepare($url, $conditionalHeaders, $knownHash);

        $pie = $this->createSimplePie($url);
        $warnings = [];
        // SimplePie scrive alcuni avvisi direttamente con error_log() se error_reporting li include
        $previousReporting = error_reporting(error_reporting() & ~(E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING | E_DEPRECATED | E_USER_DEPRECATED));
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            // Avvisi e deprecazioni di SimplePie non devono interrompere l'importazione
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
                throw new \RuntimeException($error !== '' ? $this->shorten($error) : 'Feed non leggibile.', 1731700010);
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
     * Immagine: allegato immagine più largo (enclosure / media:content), poi media:thumbnail,
     * poi le miniature degli allegati, infine il primo <img> del testo.
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
     * RSS 2.0 <author> è spesso "email (Nome)": SimplePie lo restituisce intero come email.
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
     * Solo URL http(s) assoluti: blocca "javascript:", "data:" e simili.
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
     * SimplePie restituisce i testi con entità HTML: si riportano a testo semplice.
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
