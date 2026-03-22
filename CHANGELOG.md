# Changelog

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
