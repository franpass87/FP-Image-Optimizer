# FP Image Optimizer

![Version](https://img.shields.io/badge/version-1.7.9-blue.svg)

Plugin WordPress che converte le immagini della Media Library in **WebP** e **AVIF** per ridurre il peso e migliorare le performance di caricamento.

## Funzionalità

- **Conversione automatica**: genera varianti WebP e AVIF al caricamento di nuove immagini (JPG, PNG, GIF)
- **Sostituzione nel contenuto**: usa il tag `<picture>` con `srcset` responsive per servire WebP/AVIF ai browser compatibili, con fallback al formato originale
- **Conversione manuale**: azione "Converti in WebP/AVIF" nella Media Library per le immagini esistenti
- **Configurabile**: abilita/disabilita formati, imposta qualità, attiva/disattiva la sostituzione nel frontend
- **Rinominamento all'upload**: formato `nome-sito-slug-id` (solo nuovi upload)
- **Rinomina per contenuto**: pagina admin con tab Pagine/Articoli per rinominare one-click le immagini nei contenuti
- **Duplicato al salvataggio**: al salvataggio di post/pagina crea una copia di ogni immagine con nome contestuale e aggiorna il contenuto
- **Attributi SEO**: genera automaticamente alt, title e caption dall'immagine contestuale (titolo pagina, slug)

**Sicurezza per siti esistenti**: le foto originali non vengono mai modificate o eliminate. La conversione crea solo nuovi file (.webp, .avif) e le varianti vengono servite solo se valide (non corrotte). Se WebP/AVIF non sono disponibili o la conversione fallisce, viene sempre usata l'immagine originale.

## Requisiti

- **PHP** 8.0+
- **WordPress** 6.0+
- **GD** con supporto WebP (standard in PHP 7+) oppure **Imagick**
- **AVIF**: richiede GD con libavif (PHP 8.1+) oppure Imagick con supporto AVIF

## Installazione

1. Clona o scarica il plugin nella cartella `wp-content/plugins/FP-Image-Optimizer`
2. Esegui `composer install` nella cartella del plugin
3. Attiva il plugin da WordPress → Plugin
4. Configura le impostazioni in Impostazioni → FP Image Optimizer

## Utilizzo

1. Vai su **Impostazioni → FP Image Optimizer**
2. Attiva "Abilita ottimizzazione"
3. Seleziona i formati desiderati (WebP, AVIF)
4. Le nuove immagini caricate verranno convertite automaticamente
5. Per le immagini esistenti: Media → Libreria → passa con il mouse sull'immagine → "Converti in WebP/AVIF"

## Struttura

```
FP-Image-Optimizer/
├── fp-image-optimizer.php    # Main file
├── composer.json
├── src/
│   ├── Core/Plugin.php
│   ├── Admin/
│   │   ├── Settings.php
│   │   └── SettingsPage.php
│   ├── Frontend/PictureReplacer.php
│   └── Services/ImageConverter.php
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── uninstall.php
```

## Hook e filtri

| Hook | Tipo | Descrizione |
|------|------|-------------|
| `wp_generate_attachment_metadata` | filter | Genera varianti WebP/AVIF per ogni dimensione |
| `the_content` | filter | Sostituisce img con picture (se abilitato) |
| `post_thumbnail_html` | filter | Sostituisce thumbnail con picture |
| `woocommerce_single_product_image_thumbnail_html` | filter | Sostituisce immagini galleria prodotti WooCommerce |
| `media_row_actions` | filter | Aggiunge azione "Converti in WebP/AVIF" |
| `fp_imgopt_skip_picture_replace` | filter | `(bool $skip, string $src)` — salta la sostituzione picture per un'immagine |
| `fp_imgopt_variant_urls` | filter | `(array $variants, string $src)` — modifica gli URL delle varianti prima dell'output |
| `fp_imgopt_picture_html` | filter | `(string $html, string $src, array $variants)` — modifica l'HTML finale del picture |
| `fp_imgopt_attachment_converted` | action | `(int $attachment_id, array $result)` — eseguito dopo conversione singola o bulk |
| `fp_imgopt_bulk_batch_size` | filter | `(int $size)` — allegati per batch bulk AJAX e cron (default **5**, max 50); alza solo su server potenti, abbassa (es. 3) se ricevi 503/timeout |
| `fp_imgopt_max_source_pixels` | filter | `(int $max)` — se maggiore di 0, non convertire file la cui area in pixel supera questo valore (default **20.000.000**); **0** = nessun limite |

## Autore

**Francesco Passeri**
- Sito: [francescopasseri.com](https://francescopasseri.com)
- Email: [info@francescopasseri.com](mailto:info@francescopasseri.com)
- GitHub: [github.com/franpass87](https://github.com/franpass87)
