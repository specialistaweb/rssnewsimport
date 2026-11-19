<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Service;

use SpecialistaWeb\RssNewsImport\Configuration\ExtensionSettings;
use SpecialistaWeb\RssNewsImport\Domain\Dto\FeedItem;
use SpecialistaWeb\RssNewsImport\Domain\Dto\ImportResult;
use SpecialistaWeb\RssNewsImport\Domain\Repository\FeedRepository;
use SpecialistaWeb\RssNewsImport\Feed\FeedReader;
use SpecialistaWeb\RssNewsImport\Feed\KeywordFilter;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Html\DefaultSanitizerBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\HtmlSanitizer\Sanitizer;

/**
 * Importa un feed in tx_news_domain_model_news tramite DataHandler.
 * Lettura e parsing del feed: SimplePie (vedi FeedReader).
 *
 * Ottimizzazioni:
 * - intervallo minimo per feed + backoff esponenziale sugli errori (meno richieste ai siti esterni)
 * - GET condizionale (ETag/Last-Modified) e confronto impronta del contenuto: feed invariato = nessun parsing, nessuna query
 * - una query per capire quali elementi esistono già; update solo se il contenuto è cambiato (impronta)
 * - inserimenti raggruppati in un DataHandler per blocco, senza sys_log e senza rilettura dei record
 * - pulizia HTML e download immagini solo per gli elementi nuovi, con tetto di immagini per esecuzione
 */
final class FeedImporter
{
    private const NEWS_TABLE = 'tx_news_domain_model_news';
    private const LINK_TABLE = 'tx_news_domain_model_link';
    private const BATCH_SIZE = 25;
    private const MAX_BACKOFF_SECONDS = 86400;

    private int $imagesThisRun = 0;
    private ?Sanitizer $sanitizer = null;

    public function __construct(
        private readonly FeedRepository $feedRepository,
        private readonly FeedReader $feedReader,
        private readonly ImageImporter $imageImporter,
        private readonly ExtensionSettings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Il feed va controllato ora? Rispetta l'intervallo del feed e, dopo errori consecutivi,
     * raddoppia l'attesa (max 24 ore) per non martellare un sito non raggiungibile.
     *
     * @param array<string, mixed> $feed
     */
    public function isDue(array $feed, int $now): bool
    {
        $interval = max(5, (int)$feed['check_interval']) * 60;
        $errors = min((int)$feed['error_count'], 8);
        $wait = $errors > 0 ? min($interval * (2 ** $errors), max($interval, self::MAX_BACKOFF_SECONDS)) : $interval;
        // Tolleranza di 60 s per le piccole variazioni di avvio dello Scheduler
        return (int)$feed['last_import'] + $wait - 60 <= $now;
    }

    /**
     * @param array<string, mixed> $feed riga di tx_rssnewsimport_feed
     */
    public function import(array $feed, bool $dryRun = false, bool $force = false): ImportResult
    {
        $feedUid = (int)$feed['uid'];
        $result = new ImportResult($feedUid, (string)$feed['title']);
        $url = trim((string)$feed['url']);
        $urlKey = sha1($url);

        $state = json_decode((string)($feed['http_state'] ?? ''), true);
        $state = is_array($state) && ($state['url'] ?? '') === $urlKey ? $state : ['url' => $urlKey];

        // Keywords changed since the last successful run: process the feed again even if unchanged
        $keywordFilter = new KeywordFilter((string)($feed['exclude_keywords'] ?? ''));
        $filterChanged = ($state['filter'] ?? '') !== $keywordFilter->getFingerprint();
        $reprocess = $force || $filterChanged;

        $headers = [];
        if (!$reprocess) {
            if (($state['etag'] ?? '') !== '') {
                $headers['If-None-Match'] = (string)$state['etag'];
            }
            if (($state['lastModified'] ?? '') !== '') {
                $headers['If-Modified-Since'] = (string)$state['lastModified'];
            }
        }

        try {
            $read = $this->feedReader->read($url, $headers, $reprocess ? '' : (string)($state['hash'] ?? ''));
        } catch (\Throwable $e) {
            return $this->fail($result, $feed, $state, $e, $dryRun);
        }

        if ($read->etag !== '' || $read->lastModified !== '') {
            $state['etag'] = $read->etag;
            $state['lastModified'] = $read->lastModified;
        }
        if ($read->notModified) {
            // HTTP 304 oppure contenuto identico all'ultima importazione riuscita: nessun parsing, nessuna query
            $result->notModified = true;
            $this->finish($result, $feed, $state, $dryRun);
            return $result;
        }
        $contentHash = $read->contentHash;

        try {
            $this->processItems($this->filterItems($read->items, $feed, $keywordFilter, $result), $feed, $result, $dryRun);
        } catch (\Throwable $e) {
            // Errore imprevisto (es. database): il feed verrà ritentato
            $result->addError('Errore di elaborazione: ' . $e->getMessage());
            $this->logger->error('Feed {uid}: {message}', ['uid' => $feedUid, 'message' => $e->getMessage(), 'exception' => $e]);
        }
        unset($read);

        // L'impronta si salva solo se tutto è andato bene: in caso di avvisi il feed viene rielaborato
        if (!$result->hasErrors() && $contentHash !== '') {
            $state['hash'] = $contentHash;
            $state['filter'] = $keywordFilter->getFingerprint();
        } else {
            unset($state['hash']);
        }
        $this->finish($result, $feed, $state, $dryRun);
        return $result;
    }

    /**
     * @param FeedItem[] $items
     * @param array<string, mixed> $feed
     */
    private function processItems(array $items, array $feed, ImportResult $result, bool $dryRun): void
    {
        if ($items === []) {
            return;
        }
        $importSource = FeedRepository::buildImportSource((int)$feed['uid']);
        $existing = $this->feedRepository->findImportedNews(
            $importSource,
            array_map(static fn(FeedItem $i): string => $i->getImportId(), $items)
        );

        $new = [];
        $updates = [];
        foreach ($items as $item) {
            $row = $existing[$item->getImportId()] ?? null;
            if ($row === null) {
                $new[] = $item;
            } elseif ($row['deleted'] || !(bool)$feed['update_existing'] || $row['hash'] === $item->getContentHash()) {
                $result->skipped++;
            } else {
                $updates[$row['uid']] = $item;
            }
        }

        if ($dryRun) {
            $result->created = count($new);
            $result->updated = count($updates);
            return;
        }

        if ($new !== []) {
            $pid = (int)$feed['storage_pid'] > 0 ? (int)$feed['storage_pid'] : (int)$feed['pid'];
            $fixedCategories = $this->feedRepository->findCategoryUids((int)$feed['uid']);
            // Dal più vecchio al più recente: l'ordine di creazione segue quello di pubblicazione
            foreach (array_chunk(array_reverse($new), self::BATCH_SIZE) as $chunk) {
                $this->createNews($chunk, $feed, $pid, $importSource, $fixedCategories, $result);
            }
        }
        foreach (array_chunk($updates, self::BATCH_SIZE, true) as $chunk) {
            $this->updateNews($chunk, $feed, $importSource, $result);
        }
    }

    /**
     * @param FeedItem[] $items
     * @param array<string, mixed> $feed
     * @param int[] $fixedCategories
     */
    private function createNews(array $items, array $feed, int $pid, string $importSource, array $fixedCategories, ImportResult $result): void
    {
        $news = [];
        $links = [];
        $references = [];
        $map = [];
        $isExternal = (string)$feed['news_type'] === '2';

        foreach ($items as $n => $item) {
            $newsId = 'NEWrni' . $n;
            $fields = $this->buildNewsFields($item, $feed);
            $fields['pid'] = $pid;
            $fields['hidden'] = (int)(bool)$feed['import_hidden'];
            // Slug vuoto: DataHandler lo genera secondo la configurazione TCA di EXT:news
            $fields['path_segment'] = '';

            $categories = $fixedCategories;
            if ((bool)$feed['map_categories'] && $item->categories !== []) {
                $categories = array_merge($categories, $this->feedRepository->findCategoryUidsByTitles($item->categories));
            }
            if ($categories !== []) {
                $fields['categories'] = implode(',', array_unique($categories));
            }

            if ((bool)$feed['add_related_link'] && $item->link !== '' && !$isExternal) {
                $linkId = 'NEWrnilink' . $n;
                $links[$linkId] = [
                    'pid' => $pid,
                    'title' => mb_substr((string)$feed['title'], 0, 255),
                    'uri' => $item->link,
                ];
                $fields['related_links'] = $linkId;
            }

            if ((bool)$feed['import_images'] && $item->imageUrl !== '') {
                $fileUid = $this->importImage($item, $result);
                if ($fileUid > 0) {
                    $refId = 'NEWrniref' . $n;
                    $references[$refId] = [
                        'pid' => $pid,
                        'uid_local' => $fileUid,
                        'uid_foreign' => $newsId,
                        'tablenames' => self::NEWS_TABLE,
                        'fieldname' => 'fal_media',
                        'showinpreview' => 1,
                        'alternative' => mb_substr($item->title, 0, 255),
                    ];
                    $fields['fal_media'] = $refId;
                }
            }

            $news[$newsId] = $fields;
            $map[$newsId] = $item;
        }

        // Il record news precede i record figli che vi fanno riferimento
        $data = [self::NEWS_TABLE => $news];
        if ($links !== []) {
            $data[self::LINK_TABLE] = $links;
        }
        if ($references !== []) {
            $data['sys_file_reference'] = $references;
        }

        $dataHandler = $this->process($data);

        $marks = [];
        foreach ($map as $newsId => $item) {
            $uid = (int)($dataHandler->substNEWwithIDs[$newsId] ?? 0);
            if ($uid > 0) {
                $marks[$uid] = ['importId' => $item->getImportId(), 'hash' => $item->getContentHash()];
                $result->created++;
            } else {
                $result->addError('News non creata: "' . mb_substr($item->title, 0, 80) . '"');
            }
        }
        if ($dataHandler->errorLog !== []) {
            $result->addError('DataHandler: ' . implode('; ', array_slice($dataHandler->errorLog, 0, 3)));
        }
        $this->feedRepository->markImported($importSource, $marks);
    }

    /**
     * Aggiorna solo i testi (non immagini, categorie o visibilità decise dalla redazione).
     *
     * @param array<int, FeedItem> $items uid news → elemento
     * @param array<string, mixed> $feed
     */
    private function updateNews(array $items, array $feed, string $importSource, ImportResult $result): void
    {
        $data = [];
        $marks = [];
        foreach ($items as $uid => $item) {
            $fields = $this->buildNewsFields($item, $feed);
            unset($fields['type']);
            $data[self::NEWS_TABLE][$uid] = $fields;
            $marks[$uid] = ['hash' => $item->getContentHash()];
        }
        $dataHandler = $this->process($data);
        if ($dataHandler->errorLog !== []) {
            $result->addError('DataHandler: ' . implode('; ', array_slice($dataHandler->errorLog, 0, 3)));
            return; // impronte non aggiornate: verrà ritentato
        }
        $this->feedRepository->markImported($importSource, $marks);
        $result->updated += count($items);
    }

    private function importImage(FeedItem $item, ImportResult $result): int
    {
        if ($this->imagesThisRun >= $this->settings->getMaxImagesPerRun()) {
            return 0; // tetto raggiunto: la news viene comunque creata, senza immagine
        }
        $url = $this->absoluteUrl($item->imageUrl, $item->link);
        if (!preg_match('#^https?://#i', $url)) {
            return 0;
        }
        $this->imagesThisRun++;
        try {
            $file = $this->imageImporter->import($url);
            $result->images++;
            return $file->getUid();
        } catch (\Throwable $e) {
            // Un'immagine non scaricabile non blocca la news (e non fa ritentare l'intero feed)
            $this->logger->notice('Immagine non importata {url}: {message}', ['url' => $url, 'message' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * @param FeedItem[] $items
     * @param array<string, mixed> $feed
     * @return FeedItem[]
     */
    private function filterItems(array $items, array $feed, KeywordFilter $keywordFilter, ImportResult $result): array
    {
        $maxAge = (int)$feed['max_age_days'];
        $limitDate = $maxAge > 0 ? time() - $maxAge * 86400 : 0;
        $unique = [];
        foreach ($items as $item) {
            if ($item->title === '' && $item->link === '') {
                continue;
            }
            if ($limitDate > 0 && $item->publishedAt !== null && $item->publishedAt->getTimestamp() < $limitDate) {
                continue;
            }
            // Excluded by keyword: never created nor updated (and it costs no database query)
            if ($keywordFilter->isActive() && $keywordFilter->findMatch($item) !== null) {
                $result->excluded++;
                continue;
            }
            // Elimina doppioni all'interno dello stesso feed
            $unique[$item->getImportId()] ??= $item;
        }
        $items = array_values($unique);

        usort($items, static fn(FeedItem $a, FeedItem $b): int
            => ($b->publishedAt?->getTimestamp() ?? 0) <=> ($a->publishedAt?->getTimestamp() ?? 0));

        $limit = (int)$feed['import_limit'];
        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    }

    /**
     * @param array<string, mixed> $feed
     * @return array<string, mixed>
     */
    private function buildNewsFields(FeedItem $item, array $feed): array
    {
        $html = $item->content !== '' ? $item->content : $item->description;
        $bodytext = $this->cleanHtml($html, $item->link);

        $teaser = $this->plainText($item->description !== '' ? $item->description : $item->content);
        $teaserLength = $this->settings->getTeaserLength();
        if ($teaserLength > 0 && mb_strlen($teaser) > $teaserLength) {
            $cut = mb_substr($teaser, 0, $teaserLength);
            $lastSpace = mb_strrpos($cut, ' ');
            $teaser = rtrim($lastSpace !== false && $lastSpace > $teaserLength * 0.6 ? mb_substr($cut, 0, $lastSpace) : $cut, ' ,.;:') . '…';
        }

        $isExternal = (string)$feed['news_type'] === '2' && $item->link !== '';
        $author = $item->author !== '' ? $item->author : (string)$feed['default_author'];
        $email = filter_var($item->authorEmail, FILTER_VALIDATE_EMAIL) ? $item->authorEmail : '';

        return [
            'type' => $isExternal ? '2' : '0',
            'title' => mb_substr($item->title !== '' ? $item->title : $item->link, 0, 255),
            'teaser' => $teaser,
            'bodytext' => $bodytext,
            'datetime' => min(($item->publishedAt ?? new \DateTimeImmutable())->getTimestamp(), time()),
            'author' => mb_substr($author, 0, 255),
            'author_email' => mb_substr($email, 0, 255),
            'externalurl' => $isExternal ? $item->link : '',
        ];
    }

    private function cleanHtml(string $html, string $baseUrl): string
    {
        if (trim($html) === '') {
            return '';
        }
        // Tetto di dimensione prima delle regex: tempi e memoria prevedibili
        $max = $this->settings->getMaxBodySizeBytes();
        if (strlen($html) > $max) {
            $html = mb_strcut($html, 0, $max, 'UTF-8');
        }

        $html = strip_tags(self::removeBlocks($html), $this->settings->getAllowedTags());
        $html = (string)preg_replace('/\s(style|class|id|on\w+|srcset|sizes)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string)preg_replace_callback(
            '/\s(href|src)\s*=\s*(["\'])(.*?)\2/i',
            fn(array $m): string => ' ' . $m[1] . '=' . $m[2]
                . htmlspecialchars($this->absoluteUrl(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $baseUrl), ENT_QUOTES | ENT_HTML5)
                . $m[2],
            $html
        );
        // Immagini senza un indirizzo http(s) valido (es. schemi neutralizzati sopra): eliminate
        $html = (string)preg_replace('/<img\b(?![^>]*\ssrc=["\']https?:)[^>]*>/i', '', $html);
        $html = (string)preg_replace('/<a\s(?![^>]*\btarget=)/i', '<a target="_blank" rel="noopener noreferrer nofollow" ', $html);
        $html = (string)preg_replace('/<img\s(?![^>]*\bloading=)/i', '<img loading="lazy" ', $html);

        // Sanitizer del core (allowlist di tag/attributi/protocolli): elimina javascript:, data:, ecc.
        $this->sanitizer ??= GeneralUtility::makeInstance(DefaultSanitizerBuilder::class)->build();
        $html = $this->sanitizer->sanitize($html);

        if (!preg_match('/<(p|ul|ol|h\d|blockquote|table|figure)\b/i', $html)) {
            $paragraphs = preg_split('/(\r?\n\s*){2,}|(<br\s*\/?>\s*){2,}/i', trim($html)) ?: [];
            $html = implode("\n", array_map(
                static fn(string $p): string => '<p>' . trim($p) . '</p>',
                array_filter($paragraphs, static fn(string $p): bool => trim($p) !== '')
            ));
        }
        return trim($html);
    }

    /**
     * Rimuove script, stili, iframe ecc. insieme al loro contenuto (strip_tags ne lascerebbe il testo).
     * Scansione lineare con stripos: nessuna regex con backtracking, tempi prevedibili anche
     * con HTML malformato (tag mai chiusi).
     */
    private static function removeBlocks(string $html): string
    {
        foreach (['script', 'style', 'iframe', 'noscript', 'object', 'embed', 'form', 'svg', 'math', 'template'] as $tag) {
            $offset = 0;
            $out = '';
            while (($start = stripos($html, '<' . $tag, $offset)) !== false) {
                $next = $html[$start + strlen($tag) + 1] ?? '';
                if ($next !== '' && !ctype_space($next) && $next !== '>' && $next !== '/') {
                    // Es. "<scripture": non è il tag cercato
                    $out .= substr($html, $offset, $start - $offset + 1);
                    $offset = $start + 1;
                    continue;
                }
                $out .= substr($html, $offset, $start - $offset);
                $end = stripos($html, '</' . $tag, $start);
                if ($end === false) {
                    $offset = strlen($html); // tag mai chiuso: si scarta il resto
                    break;
                }
                $close = strpos($html, '>', $end);
                $offset = $close === false ? strlen($html) : $close + 1;
            }
            $html = $out . substr($html, $offset);
        }
        return $html;
    }

    private function plainText(string $html): string
    {
        $html = self::removeBlocks(mb_strcut($html, 0, 100000, 'UTF-8'));
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function absoluteUrl(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^(https?:|mailto:|\#)#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return '#'; // altri schemi (javascript:, data:, ...) neutralizzati
        }
        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) {
            return '#';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        return $origin . rtrim(dirname($parts['path'] ?? '/'), '/\\') . '/' . $url;
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $data
     */
    private function process(array $data): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        // Niente voci in sys_log per ogni news importata e niente rilettura dei record appena scritti
        $dataHandler->enableLogging = false;
        $dataHandler->checkStoredRecords = false;
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            $this->logger->warning('DataHandler: {errors}', ['errors' => implode('; ', $dataHandler->errorLog)]);
        }
        return $dataHandler;
    }

    /**
     * @param array<string, mixed> $feed
     * @param array<string, mixed> $state
     */
    private function fail(ImportResult $result, array $feed, array $state, \Throwable $e, bool $dryRun): ImportResult
    {
        $result->failed = true;
        $result->addError($e->getMessage());
        $this->logger->warning('Feed {uid} non importato: {message}', ['uid' => $result->feedUid, 'message' => $e->getMessage()]);
        $this->finish($result, $feed, $state, $dryRun);
        return $result;
    }

    /**
     * @param array<string, mixed> $feed
     * @param array<string, mixed> $state
     */
    private function finish(ImportResult $result, array $feed, array $state, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $errorCount = $result->failed ? (int)$feed['error_count'] + 1 : 0;
        $this->feedRepository->saveRunState(
            $result->feedUid,
            $result->toStatusText(),
            $errorCount,
            array_map('strval', array_filter($state, static fn($v): bool => $v !== '' && $v !== null))
        );
    }
}
