# Changelog

## [1.7.10] - 2026-03-25

### Fixed

- **Admin bar (frontend)**: evitato fatal su `get_current_screen` per utenti loggati con toolbar (chiamata globale + `function_exists` quando l’API Screen non è caricata).

## [1.7.9] - 2026-03-25

### Fixed

- **GD WebP/AVIF**: immagini a tavolozza convertite in truecolor con `imagepalettetotruecolor` prima di `imagewebp` / `imageavif`, eliminando warning PHP «Palette image not supported» su PNG/GIF indicizzati.

## [1.7.8] - 2026-03-25

### Fixed

- **Bulk «solo mancanti» (AJAX)**: se un blocco da 100 allegati era già tutto convertito, la risposta segnalava erroneamente fine corsa e l’offset non avanzava — immagini senza varianti più avanti (per ID) non venivano raggiunte.
- **Bulk «solo mancanti» (cron)**: stessa correzione per pagine vuote dopo il filtro; chiusura corretta quando l’ultima pagina ha meno di 100 risultati e nessun candidato (evita offset che «salta» allegati o cicli inutili).
- **Paginazione bulk/cron**: con «solo mancanti» l’offset avanza in base al numero di allegati effettivamente letti dalla query (`$raw_count`), non sempre pari a 100 — ultima pagina parziale allineata.
- **Riprova errori**: il log non viene più svuotato del tutto; restano solo gli allegati ancora in errore dopo il retry.
- **Disinstallazione**: `wp_clear_scheduled_hook('fp_imgopt_bulk_cron')` per evitare eventi cron orfani.

## [1.7.7] - 2026-03-25

### Fixed

- **Percorsi uploads**: controlli `strpos`/`realpath` sostituiti con `wp_normalize_path` + `trailingslashit` + `str_starts_with` in `ImageConverter`, `VariantRemover` e `compute_stats` — evita falsi negativi su Windows (slash misti) e prefissi ambigui (es. `uploads` vs `uploads-extra`).
- **Metadata upload**: se `file` nei metadata è vuoto o non stringa, si esce subito da `on_generate_metadata` (evita percorsi relativi errati).
- **PictureReplacer**: non duplica `loading` / `decoding` se già presenti sull’`<img>`.
- **Riprova log**: parsing difensivo delle voci log (evita TypeError se l’opzione è corrotta).
- **Performance**: cache per richiesta di `supports_webp()` / `supports_avif()` (meno chiamate a `Imagick::queryFormats()` durante batch).

### Removed

- Costante inutilizzata `SUPPORTED_SOURCE` in `ImageConverter`.

## [1.7.6] - 2026-03-25

### Fixed

- **Stabilità upload / errore critico**: `try/catch` su ogni conversione in `wp_generate_attachment_metadata` così un’eccezione Imagick/GD non blocca l’intero caricamento.
- **Imagick**: dopo aver scritto il WebP si ricarica l’immagine da disco prima dell’AVIF (la stessa istanza Imagick usata per due formati poteva causare crash o stati inconsistenti).
- **`free_image`**: gestione `null` e `try/catch` su `clear`/`destroy` Imagick.
- **Limite megapixel** (filtro `fp_imgopt_max_source_pixels`, default 20M): salta conversione su sorgenti enormi per ridurre rischio esaurimento memoria; `0` = disabilita il limite.

## [1.7.5] - 2026-03-25

### Fixed

- **Bulk optimizer / HTTP 503**: batch predefinito ridotto a **5 allegati** per richiesta (prima 20), con pausa **400 ms** tra una richiesta e la successiva; `set_time_limit(180)` sul bulk AJAX. Stesso batch per il cron «in background». Su hosting lenti o con proxy che chiude le richieste lunghe questo evita timeout e 503.
- **Messaggio 502/503/504** in admin: testo dedicato (usa «Avvia in background», filtro `fp_imgopt_bulk_batch_size`).

### Added

- Filtro `fp_imgopt_bulk_batch_size` (default 5, max 50) per regolare allegati per batch.
- Nota in pagina impostazioni: in caso di 503 usare «Avvia in background».

## [1.7.4] - 2026-03-25

### Fixed

- **AJAX admin**: parsing della risposta `admin-ajax.php` tramite testo + `JSON.parse` con messaggi in italiano se il server restituisce HTML (errore PHP, notice con `WP_DEBUG`, firewall, sessione scaduta) invece del JSON atteso — evita l’errore tecnico `Unexpected token '<'`.
- **Bulk optimizer**: `ajax_bulk_convert` avvolto in `try/catch` su `\Throwable` con risposta JSON di errore e log in `WP_DEBUG`.

## [1.7.3] - 2026-03-23

### Changed

- Menu position 56.4 per ordine alfabetico FP.

## [1.7.2] - 2026-03-23

### Fixed

- Rinomina per contenuto: il conteggio "da rinominare" considera anche i filename univoci WordPress con suffisso numerico (`...-ID-1`, `...-ID-2`), evitando che resti bloccato su "1 da rinominare".

### Changed

- Admin UI rinomina contenuto: feedback inline nella riga (senza alert bloccanti) con aggiornamento immediato dello stato.

## [1.7.1] - 2026-03-23

### Changed

- Menu admin: link rapidi nella admin bar e body class `fpimgopt-admin-shell` (pattern FP-Experiences).

## [1.7.0] - 2026-03-22

### Added

- **Rinomina per contenuto**: pagina admin con tab Pagine/Articoli per rinominare one-click le immagini nei contenuti secondo il formato nome-sito-slug-id
- **ContentImageExtractor**: estrae gli ID attachment da `post_content` (img src e srcset)
- **ImageRenamer::rename_attachment_for_post()**: rinomina un attachment con contesto post, aggiorna varianti WebP/AVIF e riferimenti nel contenuto

### Fixed

- **admin.js**: messaggio di errore AJAX rinomina ora mostrato correttamente nell'alert
- **RenameByPostPage**: sanitizzazione `$_GET['tab']` con `sanitize_text_field`

## [1.6.1] - 2025-03-22

### Fixed

- **Polling bulk**: cancellazione corretta del timer quando il bulk in background è completato (evita richieste inutili)

## [1.6.0] - 2025-03-22

### Added

- **Stato bulk in background**: polling ogni 4s con messaggio "Processate: X | Convertite: Y | Errori: Z", messaggio finale "Bulk completato"
- **Bulk "solo mancanti"**: checkbox per escludere le immagini già convertite
- **Limite statistiche**: max 2000 immagini, indicatore `capped` e asterisco nel conteggio
- **Aiuto esclusioni**: lista post type pubblici sotto i campi esclusione
- **Riprova conversioni fallite**: pulsante "Riprova" che ritenta le immagini in log e poi svuota
- **Icone colonna Media**: Dashicon `dashicons-yes-alt` al posto del solo testo
- **Supporto WooCommerce**: filtro `woocommerce_single_product_image_thumbnail_html` per galleria prodotti
- **Esclusione thumbnail piccole**: impostazione `skip_min_dimension` (0–500 px) per saltare immagini con lato minore del valore

## [1.5.0] - 2025-03-22

### Added

- **Rimozione varianti**: pulsante "Rimuovi tutte le varianti WebP/AVIF" per rollback/test (gli originali restano intatti)
- **Esclusioni**: campi per escludere post type da duplicato-al-salvataggio e da sostituzione picture; meta box "Non ottimizzare immagini" su post/pagina
- **Bulk in background**: pulsante "Avvia in background" che esegue il bulk via cron (batch ogni minuto)
- **Log conversioni fallite**: sezione con ultimi 50 errori e pulsante "Svuota log"
- **Colonna Media Library**: indicatore WebP/AVIF sulla tabella Media

## [1.4.0] - 2025-03-22

### Added

- **srcset responsive**: il tag `<picture>` genera ora `srcset` completo per WebP e AVIF quando l'immagine originale ha più dimensioni (responsive images)
- **Hook e filtri**: `fp_imgopt_skip_picture_replace`, `fp_imgopt_variant_urls`, `fp_imgopt_picture_html`, `fp_imgopt_attachment_converted` per estensibilità
- **Dashboard statistiche**: card con immagini totali, convertite, file WebP/AVIF, risparmio stimato in MB; pulsante "Aggiorna statistiche"

## [1.3.3] - 2025-03-22

### Fixed

- **ImageRenamer**: basename + blocco `..` su file dimensioni; check strrpos su URL
- **ImageConverter**: validazione path realpath, blocco `..` e duplicati; basename su size file
- **ImageDuplicatorOnSave**: basename su size file; validazione realpath per path in uploads

## [1.3.2] - 2025-03-22

### Fixed

- **PictureReplacer**: validazione path contro traversal (..) e verifica che il file sia dentro uploads

## [1.3.1] - 2025-03-22

### Fixed

- **ImageRenamer**: rollback completo di tutte le dimensioni se una fallisce (evita thumbnail rotte)
- **ImageConverter**: try/finally per liberare sempre risorse GD/Imagick
- **ImageDuplicatorOnSave**: validazione copia (filesize > 0) e rimozione file vuoti

## [1.3.0] - 2025-03-22

### Added

- **Attributi SEO (alt, title, caption)**: genera automaticamente alt text, title e caption dall'immagine contestuale (titolo pagina, slug). Solo se vuoti
- Toggle "Attributi SEO" nelle impostazioni: applica SEO all'upload e al salvataggio del post

## [1.2.0] - 2025-03-22

### Added

- **Duplicato al salvataggio**: quando salvi un articolo o una pagina, crea una copia di ogni immagine usata nel contenuto con nome contestuale (sito-slug-id)
- Seconda rinominazione: all'upload (rename) + al salvataggio (duplicato)
- Toggle "Duplicato al salvataggio" nelle impostazioni
- I duplicati vengono convertiti in WebP/AVIF se l'ottimizzazione è attiva

## [1.1.2] - 2025-03-22

### Fixed

- **WebP/AVIF**: validazione file convertiti (filesize > 100 byte) prima di servire
- Se la conversione produce file corrotto/vuoto, viene eliminato e non servito
- Non si sovrascrivono varianti WebP/AVIF già valide
- Fallback sempre sull'immagine originale se le varianti non sono valide

## [1.1.1] - 2025-03-22

### Fixed

- **Sicurezza siti esistenti**: le immagini già presenti non vengono mai rinominate (solo nuovi upload)
- Rimosso rename dall'azione "Converti" in Media Library
- Rollback automatico se il rename delle dimensioni fallisce
- Skip rename se il file è già nel formato atteso o se l'allegato ha più di 2 minuti
- PictureReplacer: corretta costruzione URL per CDN e domini custom

## [1.1.0] - 2025-03-22

### Added

- Rinominamento automatico file immagine: formato `nome-sito-slug-pagina-id` (es. `mio-sito-contatti-456.jpg`)
- Contesto da pagina/post genitore o da "media" se non associato
- Aggiornamento riferimenti nel contenuto dei post e nel guid
- Toggle "Rinomina file immagine" nelle impostazioni
- Rinominamento anche da azione "Converti in WebP/AVIF" se l'opzione è attiva

## [1.0.0] - 2025-03-22

### Added

- Conversione automatica immagini in WebP e AVIF al caricamento
- Sostituzione tag `<img>` con `<picture>` nel contenuto e nelle thumbnail
- Pagina impostazioni (Impostazioni → FP Image Optimizer) con design system FP
- Azione "Converti in WebP/AVIF" nella Media Library per immagini esistenti
- Supporto GD e Imagick per la conversione
- Configurazione qualità WebP e AVIF
