# RSS News Import 3.0 (`rssnewsimport`) for TYPO3 v14 – with SimplePie

Author: Giulia Tuveri – Specialistaweb (gt@specialistaweb.it) · Composer: `specialistaweb/rss-news-import`

Imports one or more **RSS 0.9x/1.0/2.0 and Atom 0.3/1.0** feeds from external sites into the
**EXT:news** tables by Georg Ringer (`tx_news_domain_model_news`) via the **Scheduler**, with images,
categories, a link to the original article and **Fluid** templates for the frontend.

Feeds are read by **[SimplePie](https://simplepie.org) 1.9** (formats, Media RSS, iTunes, Dublin Core,
encodings, dates, authors, categories, enclosures, relative URLs, first HTML sanitisation).

| Requirement | Version |
|---|---|
| TYPO3 | 14.0 – 14.4 (LTS 14.3 recommended) |
| PHP | 8.2+ (extensions `xml`, `xmlreader`, `dom`, `fileinfo`; recommended `intl`, `iconv`, `mbstring`) |
| simplepie/simplepie | 1.9 (installed by Composer, or the bundled copy in classic mode) |
| georgringer/news | 14.x |
| typo3/cms-scheduler | 14.x |

---

## 1. Installation

### Composer (recommended)

1. Copy the extension folder to `packages/rssnewsimport` in your project.
2. Add the path repository to the project `composer.json` (if not there yet):
   ```json
   "repositories": [
       { "type": "path", "url": "packages/*" }
   ]
   ```
3. Run:
   ```bash
   composer require specialistaweb/rss-news-import:@dev
   vendor/bin/typo3 extension:setup
   vendor/bin/typo3 cache:flush
   ```

Composer also installs `simplepie/simplepie` automatically.

### Classic mode (without Composer)

SimplePie 1.9.0 is bundled in `Resources/Private/PHP/SimplePie` (BSD-3-Clause license) and is loaded
automatically only if it is not already available in the system.

1. Backend → **Admin Tools → Extensions** → upload button → upload `rssnewsimport_3.0.0.zip`.
2. Activate the extension (it creates the table `tx_rssnewsimport_feed`).
3. **Admin Tools → Maintenance → Analyze Database Structure** to be sure.

---

## 2. Feed configuration

1. Create (or use) a **folder** (sys folder) for the news, e.g. "Imported news".
2. In the **List** module on that folder create one **RSS feed** record per source site:

| Field | Description |
|---|---|
| Source name | Shown in the frontend as "Source: …" |
| Feed URL | **Direct** URL of the RSS/Atom feed (e.g. `https://www.example.com/feed/`), not the home page: autodiscovery is disabled for security |
| Target folder | Where the news are stored; empty = the folder of the feed record |
| News type | *Regular news* (text on the site + link to the original) or *External page* (the title links straight to the original article) |
| Download images | Downloads the first image (enclosure, `media:content`, `media:thumbnail` or first `<img>`) into FAL and attaches it as preview image |
| Add link to the original | Creates an EXT:news *related link* to the source article |
| Update already imported news | Updates title, teaser, text and date when they change in the feed |
| Import as hidden | News arrive hidden and the editors publish them manually |
| Categories | System categories assigned to all news of the feed |
| Map feed categories | Maps the `<category>` elements of the feed to existing `sys_category` records with the same title |
| Exclude news containing… (tab *Filters*) | Comma separated keywords: a news item is not imported if its title or text contains one of them (see below) |
| Check interval | How often (in minutes) the site is contacted (default 60), regardless of how often the Scheduler runs |
| Maximum number / maximum age | Limits per run (0 = no limit) |
| Last import / Consecutive errors / Last result | Filled in automatically on every run |

To pause a feed temporarily, simply **hide** it.

### Keyword filter

In the **Filters** tab of the feed record you can enter words or phrases separated by commas
(also one per line). A news item is skipped if its **title, summary or text** contains at least one of them.

Example: you import sports news and want to skip the ones about betting:

    betting, odds, bookmaker, casino

Rules:
- upper and lower case do not matter; HTML tags and entities (`&eacute;`) do not interfere;
- only **whole words or phrases** match: `bet` does not exclude `better`;
- a phrase with spaces also matches the same text split over lines or with several spaces;
- skipped news are not created and, if they had already been imported, they are no longer updated
  (news already published stay: delete them manually if needed);
- if you change the list, the feed is processed again at the next run, even if it did not change;
- the number of excluded news appears in "Last result" and in the "Excluded" column of the command;
- limits: 200 keywords per feed, 100 characters each. Be careful with very short or common words.

### Global settings

**Admin Tools → Settings → Extension Configuration → rssnewsimport**

- `imageFolder` – FAL folder for the images (default `1:/user_upload/rssnewsimport/`, created automatically)
- `httpTimeout`, `userAgent`
- `teaserLength` – maximum teaser length
- `allowedTags` – HTML tags kept in the text (everything else is removed and the HTML goes through the core sanitizer)
- **Limits:** `maxFeedSizeKb` (5 MB), `maxItemsPerFeed` (200), `maxImageSizeKb` (5 MB),
  `maxImageMegapixels` (40), `maxImagesPerRun` (50), `maxBodySizeKb` (200)
- **Security:** `allowPrivateHosts` (off by default) – enable it only if you must read feeds from an intranet

---

## 3. Scheduler

1. **System → Scheduler → +** (new task).
2. Class: **Execute console commands**.
3. Command: **`rssnewsimport:import`**.
4. Type: recurring, e.g. every 10–15 minutes (`*/15 * * * *`). There is no need to align it with the feeds:
   every feed is contacted only when **its own** check interval has passed, so a run without due feeds
   makes no HTTP request and only one database query.
5. Optional: in the field of the `--feed` option enter comma separated uids for a task dedicated to specific feeds.

The Scheduler must be called by the system cron, for example:

```cron
*/5 * * * * /path/to/project/vendor/bin/typo3 scheduler:run
```

### Command line

```bash
vendor/bin/typo3 rssnewsimport:import              # all active feeds
vendor/bin/typo3 rssnewsimport:import --feed=1,3   # only some feeds
vendor/bin/typo3 rssnewsimport:import --force      # ignore interval, backoff and HTTP cache
vendor/bin/typo3 rssnewsimport:import --dry-run    # simulation, nothing is written
vendor/bin/typo3 rssnewsimport:import -v           # verbose output
```

A lock prevents overlapping runs.

---

## 4. Fluid frontend

The extension provides overrides of the EXT:news templates:

- `Resources/Private/Extensions/News/Partials/List/Item.html` – list card with image, date,
  "Source" badge, categories, "Read more" and link to the original
- `Resources/Private/Extensions/News/Templates/News/Detail.html` – detail view with the box
  "This article was originally published by …"
- `Resources/Public/Css/rss-news-import.css` – minimal styles (CSS variables `--rni-*` to adapt them)

The templates work for **all** news: the specific parts (source, original link) appear only
for imported news.

Frontend labels are in English; an Italian translation is included (`it.locallang.xlf`).

### Activation

- **Site sets (TYPO3 v14, recommended):** *Site Management → Sites → edit site → Sets* →
  add **"RSS News Import (Fluid templates for EXT:news)"** after the EXT:news set.
- **Classic TypoScript record:** *Site Management → TypoScript → Edit TypoScript Record →
  Includes* → add "RSS News Import (Fluid templates for EXT:news)" **after** the News one.

Then insert the **News** plugin (list and detail) as usual, with the folder of the imported news
as *Startingpoint*.

### Customising the templates

Copy the files to your site package and register them with a higher index:

```typoscript
plugin.tx_news.view.templateRootPaths.200 = EXT:my_sitepackage/Resources/Private/Extensions/News/Templates/
plugin.tx_news.view.partialRootPaths.200 = EXT:my_sitepackage/Resources/Private/Extensions/News/Partials/
```

The following ViewHelper is available in the templates:

```html
<html xmlns:rni="http://typo3.org/ns/SpecialistaWeb/RssNewsImport/ViewHelpers" data-namespace-typo3-fluid="true">
<rni:feedInfo importSource="{newsItem.importSource}" as="feed">
    <f:if condition="{feed}">{feed.title} – {feed.host} – {feed.url}</f:if>
</rni:feedInfo>
```

To disable the CSS: `page.includeCSS.rssNewsImport >`

---

## 5. How it works

- Feeds are downloaded with the TYPO3 HTTP client (honours proxy and `HTTP` settings).
- XML is read safely (no external entities, no network access from the parser).
- Every item is identified by `import_source = rss_news_import:<feed uid>` and
  `import_id = sha1(guid | link | title)`, the standard EXT:news fields for imports:
  **no duplicates**. The `import_source` prefix keeps the old extension key on purpose, so news
  imported by earlier versions are still recognised.
- News imported and then **deleted** by the editors are not imported again.
- News are created with the **DataHandler**: slug (`path_segment`), categories, FAL references,
  reference index and caches are handled by the core as for a backend insert.
- Relative links and images in the text are made absolute; links open in a new tab.
- Images are stored with a file name derived from the URL: the same image is never downloaded twice.

## 6. How SimplePie is integrated

```
Scheduler ─► ImportFeedsCommand ─► FeedImporter ─► FeedReader ─► SimplePie
                                        │                           │ (PSR-18)
                                        │                           ▼
                                        │                  SimplePieHttpClient ─► HttpClient ─► TYPO3 HTTP stack
                                        │                                                (UrlGuard, limits, 304)
                                        └─► DataHandler ─► tx_news_domain_model_news (+ FAL, categories, links)
```

- SimplePie gets a **PSR-18 client** (`SimplePie::set_http_client()`, the method SimplePie declares
  mandatory from 2.0 on). Its internal downloader is not used because it ignores the TYPO3
  HTTP/proxy settings and has no SSRF protection.
- The transport is the TYPO3 one (Guzzle): it uses cURL if the PHP extension is available, otherwise
  PHP streams. It works in both cases.
- **SimplePie cache disabled**: ETag, Last-Modified and the feed fingerprint are stored in the feed record;
  if the feed did not change, reading stops before parsing.
- **Autodiscovery disabled**: while looking for the feed in an HTML page, SimplePie would read a `file://`
  link from the local disk; besides, every attempt would cost extra requests.
- The feed URL is validated before it is handed to SimplePie (a local path would be read from disk).
- HTML already cleaned by SimplePie (`script`, `iframe`, `svg` tags and `on*`/`style` attributes removed,
  URLs made absolute) still goes through the cleaning of the extension and the TYPO3 core sanitizer.

## 7. Performance and security

**Few requests to external sites**
- Minimum interval per feed; after consecutive errors the waiting time doubles (up to 24 h) and returns to normal after the first success.
- Conditional GET (`ETag` / `If-Modified-Since`): if the feed did not change the server answers `304` and nothing is downloaded.
- If the server does not support 304, a SHA-1 fingerprint of the content avoids parsing and queries when the feed is identical.
- gzip/deflate compression, one connection per feed, DNS cache per run.
- Images: never downloaded twice (file name derived from the URL), at most `maxImagesPerRun` per run.

**Light on the database**
- Unchanged feed: one single `UPDATE` of the feed record.
- One indexed query to know which feed items already exist (index `rssnewsimport_import` added on
  `import_source, import_id` of the news table).
- Updates only for news whose content really changed (column `tx_rssnewsimport_hash`), without reading titles or texts.
- Grouped inserts (25 news per DataHandler), **without `sys_log` entries** and without reading the records back.
- Category map read once per run; existing images checked with one query on `sys_file.identifier_hash`.
- Frontend: no query for news that were not imported, at most one per feed and request (then page cache).
- Cache: EXT:news clears it only for the pages of the affected folder.

**Limited, predictable resources**
- Download written to a limited buffer (`LimitedStream`, max 1 MB in RAM, then on disk) with a byte cap (also valid after decompression: no "zip bomb"); images written directly to a temporary file.
- Caps on items per feed, text length, image size and megapixels.
- HTML cleaning with a linear scan (no regular expression prone to backtracking on malformed HTML).
- Lock: two runs cannot overlap.

**Security**
- **SSRF:** feeds, images and every redirect accept only `http/https` to public IPs (no localhost, private networks, link-local/cloud metadata, CGNAT), without credentials in the URL; if PHP has the cURL extension, the verified IP is pinned in the connection (`CURLOPT_RESOLVE`) against DNS rebinding; without cURL the PHP stream handler is used with validation only.
- **XXE / billion laughs:** responses containing `<!ENTITY>` are rejected before they reach SimplePie.
- **XSS:** HTML cleaned (tag allowlist, `on*`/`style` attributes removed, `javascript:`/`data:` schemes neutralised) and then passed through the core sanitizer; news links accepted only if `http(s)`; Fluid output always escaped.
- **Files:** only JPEG/PNG/GIF/WebP/AVIF verified on the real content (no SVG); generated file names, no path taken from the feed.
- **Queries:** QueryBuilder with parameters only; writes through the DataHandler.
- External links in the text get `rel="noopener noreferrer nofollow"`.

## 8. Troubleshooting

- **"Last result"** in the feed record shows the latest errors.
- Log: `var/log/typo3_*.log` (channel `SpecialistaWeb.RssNewsImport`).
- Manual test: `vendor/bin/typo3 rssnewsimport:import --feed=<uid> --force --dry-run -v`.
- "A feed could not be found at …": the URL is not a feed (e.g. the home page). Use the direct feed URL,
  usually shown by the RSS icon of the site or in `<link rel="alternate" type="application/rss+xml">`.
- "Host in a private/reserved network not allowed": the feed points to an internal address; if this is intended, enable `allowPrivateHosts`.
- HTTP error 403: some sites block bots; change `userAgent` in the configuration.
- Missing images: check that the FAL folder is writable and that `maxImageSizeKb` is large enough.

## Changelog

### 3.0.0

- **New extension key `rssnewsimport`** (was `rss_news_import`), as registered in the TER;
  `composer.json` contains it in `extra.typo3/cms.extension-key`.
- All strings in English: code comments, messages of the console command and of the log,
  extension configuration labels, README. The Italian translation of the backend and frontend
  labels is still included in separate files (`it.locallang*.xlf`).
- TCA: `ctrl.searchFields` removed (no longer supported by TYPO3 v14).

**Upgrading from 2.x** (the database table `tx_rssnewsimport_feed`, the news and the imported images stay as they are):

1. *Admin Tools → Extensions*: deactivate the old extension `rss_news_import` (answer **no** if you are asked to
   remove its database tables), then upload and activate `rssnewsimport`. With Composer the package name does
   not change: `composer update specialistaweb/rss-news-import` and `vendor/bin/typo3 extension:setup`.
2. *Maintenance → Analyze Database Structure* and *Flush TYPO3 and PHP Cache*
   (classic mode: also *Rebuild PHP Autoload Information*).
3. Extension configuration: the values saved for `rss_news_import` are still read; save them once in
   *Settings → Extension Configuration → rssnewsimport* to move them to the new key.
4. Classic TypoScript record: remove the old static template and add "RSS News Import (Fluid templates for EXT:news)"
   again (the site set `specialistaweb/rss-news-import` does not change).
5. The Scheduler task (`rssnewsimport:import`) does not change. Already imported news are recognised: no duplicates.

### 2.0.1

- Keyword filter per feed (field `exclude_keywords`, tab *Filters*).
- Composer name `specialistaweb/rss-news-import` and PHP namespace `SpecialistaWeb\RssNewsImport`
  (before `custom/…` and `Custom\…`); site set `specialistaweb/rss-news-import`.
- Author and English description in `ext_emconf.php` and `composer.json`.

## Structure

```
rssnewsimport/
├── Classes/
│   ├── Command/ImportFeedsCommand.php      console command / Scheduler
│   ├── Configuration/ExtensionSettings.php
│   ├── Domain/Dto/                         FeedItem, FeedReadResult, ImportResult, HttpResponse
│   ├── Domain/Repository/FeedRepository.php
│   ├── Feed/FeedReader.php                 feed reading with SimplePie
│   ├── Feed/KeywordFilter.php              keyword filter
│   ├── Http/SimplePieHttpClient.php        PSR-18 client for SimplePie
│   ├── Http/LimitedStream.php              download buffer with byte limit
│   ├── Security/UrlGuard.php               SSRF protection
│   ├── Service/                            FeedImporter, HttpClient, ImageImporter
│   └── ViewHelpers/FeedInfoViewHelper.php
├── Configuration/  TCA, Sets, TypoScript, Services.yaml, Icons.php
├── Resources/      Fluid templates, languages (en + it), CSS, icons, SimplePie (Private/PHP)
├── Tests/Fixtures/ sample feeds
├── composer.json · ext_emconf.php · ext_localconf.php · ext_tables.sql · ext_conf_template.txt
```

License: GPL-2.0-or-later
