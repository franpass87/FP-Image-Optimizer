# Changelog

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
