# RSS News Import 2.0 (`rss_news_import`) per TYPO3 v14 – con SimplePie

Autore: Giulia Tuveri – Specialistaweb (gt@specialistaweb.it) · Composer: `specialistaweb/rss-news-import`

Importa uno o più feed **RSS 0.9x/1.0/2.0 e Atom 0.3/1.0** da siti esterni nelle tabelle di
**EXT:news** di Georg Ringer (`tx_news_domain_model_news`) tramite lo **Scheduler**, con immagini,
categorie, link all'articolo originale e template **Fluid** per il frontend.

La lettura dei feed è affidata a **[SimplePie](https://simplepie.org) 1.9** (formati, Media RSS, iTunes,
Dublin Core, codifiche, date, autori, categorie, allegati, URL relativi, prima sanitizzazione HTML).

| Requisito | Versione |
|---|---|
| TYPO3 | 14.0 – 14.4 (LTS 14.3 consigliata) |
| PHP | 8.2+ (estensioni `xml`, `xmlreader`, `dom`, `fileinfo`; consigliate `intl`, `iconv`, `mbstring`) |
| simplepie/simplepie | 1.9 (installato da Composer, oppure copia inclusa per la modalità classica) |
| georgringer/news | 14.x |
| typo3/cms-scheduler | 14.x |

---

## 1. Installazione

### Composer (consigliato)

1. Copia la cartella `rss_news_import` in `packages/rss_news_import` nel progetto.
2. Nel `composer.json` del progetto aggiungi il path repository (se non c'è già):
   ```json
   "repositories": [
       { "type": "path", "url": "packages/*" }
   ]
   ```
3. Esegui:
   ```bash
   composer require specialistaweb/rss-news-import:@dev
   vendor/bin/typo3 extension:setup
   vendor/bin/typo3 cache:flush
   ```

Composer installa automaticamente anche `simplepie/simplepie`.

### Modalità classica (senza Composer)

SimplePie 1.9.0 è incluso in `Resources/Private/PHP/SimplePie` (licenza BSD-3-Clause) e viene caricato
automaticamente solo se non è già presente nel sistema.

1. Backend → **Admin Tools → Extensions** → pulsante di upload → carica `rss_news_import.zip`.
2. Attiva l'estensione (crea automaticamente la tabella `tx_rssnewsimport_feed`).
3. **Admin Tools → Maintenance → Analyze Database Structure** per sicurezza.

---

### Aggiornamento dalla versione 1.x

1. Sostituisci l'estensione e svuota tutte le cache.
2. *Maintenance → Analyze Database Structure* (nessuna modifica attesa rispetto alla 1.1).
3. Le news già importate **vengono riconosciute**: l'identificativo (guid/id → link → titolo) è calcolato
   come nella 1.x, quindi non ci sono duplicati.
4. Se hai attivato "Aggiorna le news già importate", alla prima esecuzione alcune news potrebbero essere
   riscritte una volta, perché SimplePie normalizza l'HTML in modo leggermente diverso dal parser 1.x.

## 2. Configurazione dei feed

1. Crea (o usa) una **cartella di sistema** per le news, ad es. "News importate".
2. Nel modulo **Elenco** su quella cartella crea un record **Feed RSS** per ogni sito sorgente:

| Campo | Descrizione |
|---|---|
| Nome della fonte | Mostrato nel frontend come "Fonte: …" |
| URL del feed | URL **diretto** del feed RSS/Atom (es. `https://www.sito.it/feed/`), non la home page: l'autodiscovery è disattivato per sicurezza |
| Cartella di destinazione | Dove salvare le news; vuoto = la cartella del record feed |
| Tipo di news | *News normale* (testo sul sito + link all'originale) oppure *Pagina esterna* (il titolo porta direttamente all'articolo originale) |
| Scarica le immagini | Scarica la prima immagine (enclosure, `media:content`, `media:thumbnail` o primo `<img>`) in FAL e la collega come immagine di anteprima |
| Aggiungi link all'originale | Crea un *related link* di EXT:news verso l'articolo sorgente |
| Aggiorna le news già importate | Aggiorna titolo, teaser, testo e data se cambiano nel feed |
| Importa come nascoste | Le news arrivano nascoste, la redazione le pubblica a mano |
| Categorie | Categorie di sistema assegnate a tutte le news del feed |
| Associa le categorie del feed | Collega le `<category>` del feed alle `sys_category` esistenti con lo stesso titolo |
| Escludi le news che contengono… (scheda *Filtri*) | Parole chiave separate da virgola: la news non viene importata se titolo o testo ne contengono una (vedi sotto) |
| Intervallo di controllo | Ogni quanti minuti contattare il sito (default 60), indipendentemente da quanto spesso gira lo Scheduler |
| Numero massimo / età massima | Limiti per ogni esecuzione (0 = nessun limite) |
| Ultima importazione / Errori consecutivi / Ultimo esito | Compilati automaticamente a ogni esecuzione |

Per disattivare temporaneamente un feed basta **nasconderlo**.

### Filtro per parole chiave

Nella scheda **Filtri** del record feed puoi indicare parole o frasi separate da virgola
(anche una per riga). Una news viene scartata se **titolo, sommario o testo** ne contengono almeno una.

Esempio: importi le news sportive sul *calcio* e vuoi scartare quelle sul calcio come minerale:

    osteoporosi, integratori, vitamina D, ossa, alimentazione

Regole:
- maiuscole e minuscole sono indifferenti; i tag HTML e le entità (`&egrave;`) non disturbano;
- vale solo per **parole o frasi intere**: `ossa` non esclude `rossa`, `calcio` non esclude `calciomercato`;
- una frase con spazi trova anche lo stesso testo andato a capo o con più spazi;
- le news scartate non vengono create e, se erano già state importate, non vengono più aggiornate
  (quelle già pubblicate restano: cancellale a mano se serve);
- se modifichi l'elenco, alla prossima esecuzione il feed viene rielaborato subito, anche se non è cambiato;
- il numero di news escluse compare in "Ultimo esito" e nella colonna "Esclusi" del comando;
- limiti: 200 parole chiave per feed, 100 caratteri ciascuna. Attenzione alle parole molto brevi o
  comuni: `serie a` esclude anche "una serie a puntate".

### Impostazioni globali

**Admin Tools → Settings → Extension Configuration → rss_news_import**

- `imageFolder` – cartella FAL per le immagini (default `1:/user_upload/rss_news_import/`, creata automaticamente)
- `httpTimeout`, `userAgent`
- `teaserLength` – lunghezza massima del teaser
- `allowedTags` – tag HTML mantenuti nel testo (il resto viene rimosso e l'HTML passa dal sanitizer del core)
- **Limiti:** `maxFeedSizeKb` (5 MB), `maxItemsPerFeed` (200), `maxImageSizeKb` (5 MB),
  `maxImageMegapixels` (40), `maxImagesPerRun` (50), `maxBodySizeKb` (200)
- **Sicurezza:** `allowPrivateHosts` (default disattivato) – attivalo solo se devi leggere feed da una intranet

---

## 3. Scheduler

1. **System → Scheduler → +** (nuovo task).
2. Classe: **Execute console commands** (*Esegui comandi da console*).
3. Comando: **`rssnewsimport:import`**.
4. Tipo: ricorrente, ad es. ogni 10–15 minuti (`*/15 * * * *`). Non serve allinearlo ai feed:
   ogni feed viene contattato solo allo scadere del **suo** intervallo di controllo, quindi
   un'esecuzione senza feed in scadenza non fa richieste HTTP e solo una query al database.
5. Opzionale: nel campo dell'opzione `--feed` indica gli uid separati da virgola per un task dedicato a feed specifici.

Lo Scheduler deve essere richiamato dal cron di sistema, ad esempio:

```cron
*/5 * * * * /percorso/progetto/vendor/bin/typo3 scheduler:run
```

### Da riga di comando

```bash
vendor/bin/typo3 rssnewsimport:import              # tutti i feed attivi
vendor/bin/typo3 rssnewsimport:import --feed=1,3   # solo alcuni feed
vendor/bin/typo3 rssnewsimport:import --force      # ignora intervallo, backoff e cache HTTP
vendor/bin/typo3 rssnewsimport:import --dry-run    # simulazione, nessuna scrittura
vendor/bin/typo3 rssnewsimport:import -v           # output dettagliato
```

Un lock impedisce esecuzioni sovrapposte.

---

## 4. Frontend Fluid

L'estensione fornisce override dei template di EXT:news:

- `Resources/Private/Extensions/News/Partials/List/Item.html` – card della lista con immagine, data,
  badge "Fonte", categorie, "Leggi tutto" e link all'originale
- `Resources/Private/Extensions/News/Templates/News/Detail.html` – dettaglio con box
  "Questo articolo è stato pubblicato originariamente da …"
- `Resources/Public/Css/rss-news-import.css` – stile minimo (variabili CSS `--rni-*` per adattarlo)

I template funzionano per **tutte** le news: le parti specifiche (fonte, link originale)
compaiono solo per quelle importate.

### Attivazione

- **Site Sets (TYPO3 v14, consigliato):** *Site Management → Sites → modifica sito → Sets* →
  aggiungi **"RSS News Import (template Fluid per EXT:news)"** dopo il set di EXT:news.
- **Template TypoScript classico:** *Site Management → TypoScript → Edit TypoScript Record →
  Includes* → aggiungi "RSS News Import (template Fluid per EXT:news)" **dopo** quello di News.

Poi inserisci il plugin **News** (lista e dettaglio) come di consueto, con la cartella delle news
importate come *Startingpoint*.

### Personalizzare i template

Copia i file in un tuo site package e registrali con indice più alto:

```typoscript
plugin.tx_news.view.templateRootPaths.200 = EXT:mio_sitepackage/Resources/Private/Extensions/News/Templates/
plugin.tx_news.view.partialRootPaths.200 = EXT:mio_sitepackage/Resources/Private/Extensions/News/Partials/
```

Nei template è disponibile il ViewHelper:

```html
<html xmlns:rni="http://typo3.org/ns/SpecialistaWeb/RssNewsImport/ViewHelpers" data-namespace-typo3-fluid="true">
<rni:feedInfo importSource="{newsItem.importSource}" as="feed">
    <f:if condition="{feed}">{feed.title} – {feed.host} – {feed.url}</f:if>
</rni:feedInfo>
```

Per disattivare il CSS: `page.includeCSS.rssNewsImport >`

---

## 5. Come funziona

- I feed vengono scaricati con il client HTTP di TYPO3 (rispetta proxy e impostazioni `HTTP`).
- L'XML viene letto in modo sicuro (nessuna entità esterna, nessun accesso di rete dal parser).
- Ogni elemento è identificato da `import_source = rss_news_import:<uid feed>` e
  `import_id = sha1(guid | link | titolo)`, i campi standard di EXT:news per gli import:
  **nessun duplicato**.
- Le news importate e poi **cancellate** dalla redazione non vengono re-importate.
- Le news vengono create con **DataHandler**: slug (`path_segment`), categorie, riferimenti FAL,
  refindex e cache sono gestiti dal core come per un inserimento da backend.
- Link e immagini relativi nel testo vengono resi assoluti; i link si aprono in una nuova scheda.
- Le immagini sono salvate con un nome derivato dall'URL: la stessa immagine non viene scaricata due volte.

## 6. Come è integrato SimplePie

```
Scheduler ─► ImportFeedsCommand ─► FeedImporter ─► FeedReader ─► SimplePie
                                        │                           │ (PSR-18)
                                        │                           ▼
                                        │                  SimplePieHttpClient ─► HttpClient ─► stack HTTP TYPO3
                                        │                                                (UrlGuard, limiti, 304)
                                        └─► DataHandler ─► tx_news_domain_model_news (+ FAL, categorie, link)
```

- SimplePie riceve un **client PSR-18** (`SimplePie::set_http_client()`, il metodo che SimplePie indica
  come obbligatorio dalla 2.0). Il suo downloader interno non viene usato perché ignora le impostazioni
  HTTP/proxy di TYPO3 e non ha protezioni SSRF.
- Il trasporto è quello di TYPO3 (Guzzle): usa cURL se l'estensione PHP è presente, altrimenti gli stream
  di PHP. Funziona in entrambi i casi.
- **Cache di SimplePie disattivata**: ETag, Last-Modified e impronta del feed sono salvati nel record del feed;
  se il feed non è cambiato la lettura si ferma prima del parsing.
- **Autodiscovery disattivato**: SimplePie, cercando il feed in una pagina HTML, leggerebbe dal disco locale
  un link `file://`; inoltre ogni tentativo costerebbe richieste in più.
- L'URL del feed viene validato prima di passarlo a SimplePie (un percorso locale verrebbe letto dal disco).
- L'HTML già ripulito da SimplePie (tag `script`, `iframe`, `svg`, attributi `on*`/`style` rimossi, URL resi
  assoluti) passa comunque dalla pulizia dell'estensione e dal sanitizer del core TYPO3.

## 7. Prestazioni e sicurezza

**Poche richieste ai siti esterni**
- Intervallo minimo per feed; dopo errori consecutivi l'attesa raddoppia (fino a 24 h), poi torna normale al primo successo.
- GET condizionale (`ETag` / `If-Modified-Since`): se il feed non è cambiato il server risponde `304` e non si scarica nulla.
- Se il server non supporta il 304, un'impronta SHA-1 del contenuto evita parsing e query quando il feed è identico.
- Compressione gzip/deflate, una sola connessione per feed, cache DNS per esecuzione.
- Immagini: mai riscaricate (nome file derivato dall'URL), massimo `maxImagesPerRun` per esecuzione.

**Database leggero**
- Feed invariato: 1 sola `UPDATE` sul record del feed.
- Una query indicizzata per sapere quali elementi del feed esistono già (indice
  `rssnewsimport_import` aggiunto su `import_source, import_id` della tabella news).
- Aggiornamenti solo per le news il cui contenuto è davvero cambiato (colonna `tx_rssnewsimport_hash`), senza rileggere titoli o testi.
- Inserimenti raggruppati (25 news per DataHandler), **senza voci in `sys_log`** e senza rilettura dei record.
- Mappa delle categorie letta una sola volta per esecuzione; verifica immagini esistenti con una query su `sys_file.identifier_hash`.
- Frontend: nessuna query per le news non importate, al massimo una per feed per richiesta (e poi cache di pagina).
- Cache: la svuota EXT:news solo per le pagine della cartella interessata.

**Risorse limitate e prevedibili**
- Download scritto in un buffer limitato (`LimitedStream`, max 1 MB in RAM poi su disco) con tetto di byte (valido anche dopo la decompressione: niente "zip bomb"); immagini scritte direttamente su file temporaneo.
- Tetti su elementi per feed, lunghezza del testo, dimensioni e megapixel delle immagini.
- Pulizia HTML con scansione lineare (nessuna regex soggetta a backtracking su HTML malformato).
- Lock: due esecuzioni non possono sovrapporsi.

**Sicurezza**
- **SSRF:** feed, immagini e ogni redirect accettano solo `http/https` verso IP pubblici (no localhost, reti private, link-local/metadata cloud, CGNAT), senza credenziali nell'URL; se PHP ha l'estensione cURL, l'IP verificato viene bloccato nella connessione (`CURLOPT_RESOLVE`) contro il DNS rebinding; senza cURL si usa lo stream handler di PHP con la sola validazione.
- **XXE / billion laughs:** risposte con `<!ENTITY>` rifiutate prima di arrivare a SimplePie.
- **XSS:** HTML ripulito (allowlist di tag, rimozione di attributi `on*`/`style`, schemi `javascript:`/`data:` neutralizzati) e poi passato dal sanitizer del core; link della news accettati solo se `http(s)`; output Fluid sempre escapato.
- **File:** solo JPEG/PNG/GIF/WebP/AVIF verificati sul contenuto reale (no SVG); nomi file generati, nessun percorso dal feed.
- **Query:** solo QueryBuilder con parametri; scritture tramite DataHandler.
- I link esterni nel testo hanno `rel="noopener noreferrer nofollow"`.

## 8. Risoluzione problemi

- **"Ultimo esito"** nel record feed mostra gli ultimi errori.
- Log: `var/log/typo3_*.log` (canale `SpecialistaWeb.RssNewsImport`).
- Prova manuale: `vendor/bin/typo3 rssnewsimport:import --feed=<uid> --force --dry-run -v`.
- "A feed could not be found at …": l'URL non è un feed (es. la home page). Usa l'URL diretto del feed,
  di solito indicato dall'icona RSS del sito o in `<link rel="alternate" type="application/rss+xml">`.
- "Host in rete privata non consentito": il feed punta a un indirizzo interno; se è voluto, attiva `allowPrivateHosts`.
- Errore HTTP 403: alcuni siti bloccano i bot; modifica `userAgent` nella configurazione.
- Immagini mancanti: verifica che la cartella FAL sia scrivibile e che `maxImageSizeKb` sia sufficiente.

## Novità della 2.0.1

- Filtro per parole chiave per ogni feed (campo `exclude_keywords`, scheda *Filtri*).
- Nome Composer `specialistaweb/rss-news-import` e namespace PHP `SpecialistaWeb\RssNewsImport`
  (prima `custom/…` e `Custom\…`). Se hai template Fluid personalizzati che usano il ViewHelper
  `rni:feedInfo`, aggiorna il namespace in `http://typo3.org/ns/SpecialistaWeb/RssNewsImport/ViewHelpers`.
- Autore e descrizione in inglese in `ext_emconf.php` e `composer.json`.
- Il Site Set si chiama ora `specialistaweb/rss-news-import` (prima `custom/rss-news-import`).

**Aggiornamento dalla 2.0.0:** sostituisci l'estensione, poi *Maintenance → Analyze Database Structure*
(aggiunge la colonna `exclude_keywords`), *Rebuild PHP Autoload Information* (installazione classica)
e *Flush TYPO3 and PHP Cache*.
**Importante:** se il sito usa il Site Set, riaggiungilo dopo l'aggiornamento
(*Site Management → Sites → modifica sito → Sets*: togli la voce non più valida e aggiungi
"RSS News Import (template Fluid per EXT:news)"), oppure in `config/sites/<sito>/config.yaml`
sostituisci `custom/rss-news-import` con `specialistaweb/rss-news-import` sotto `dependencies`.
Senza questo passaggio i template Fluid e il CSS dell'estensione non vengono caricati. Con Composer: cambia il pacchetto in `specialistaweb/rss-news-import`
nel `composer.json` del progetto, poi `composer update` e `vendor/bin/typo3 extension:setup`.

## Struttura

```
rss_news_import/
├── Classes/
│   ├── Command/ImportFeedsCommand.php      comando console / Scheduler
│   ├── Configuration/ExtensionSettings.php
│   ├── Domain/Dto/                         FeedItem, FeedReadResult, ImportResult, HttpResponse
│   ├── Domain/Repository/FeedRepository.php
│   ├── Feed/FeedReader.php                 lettura feed con SimplePie
│   ├── Feed/KeywordFilter.php              filtro per parole chiave
│   ├── Http/SimplePieHttpClient.php        client PSR-18 per SimplePie
│   ├── Http/LimitedStream.php              buffer di download con limite di byte
│   ├── Security/UrlGuard.php               protezione SSRF
│   ├── Service/                            FeedImporter, HttpClient, ImageImporter
│   └── ViewHelpers/FeedInfoViewHelper.php
├── Configuration/  TCA, Sets, TypoScript, Services.yaml, Icons.php
├── Resources/      template Fluid, lingue (en/it), CSS, icone, SimplePie (Private/PHP)
├── Tests/Fixtures/ feed di esempio
├── composer.json · ext_emconf.php · ext_localconf.php · ext_tables.sql · ext_conf_template.txt
```

Licenza: GPL-2.0-or-later
