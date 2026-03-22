# FP Image Optimizer

![Version](https://img.shields.io/badge/version-1.1.2-blue.svg)

Plugin WordPress che converte le immagini della Media Library in **WebP** e **AVIF** per ridurre il peso e migliorare le performance di caricamento.

## Funzionalità

- **Conversione automatica**: genera varianti WebP e AVIF al caricamento di nuove immagini (JPG, PNG, GIF)
- **Sostituzione nel contenuto**: usa il tag `<picture>` per servire WebP/AVIF ai browser compatibili, con fallback al formato originale
- **Conversione manuale**: azione "Converti in WebP/AVIF" nella Media Library per le immagini esistenti
- **Configurabile**: abilita/disabilita formati, imposta qualità, attiva/disattiva la sostituzione nel frontend
- **Rinominamento SEO**: rinomina le immagini con formato `nome-sito-slug-pagina-id` (solo nuovi upload, mai immagini esistenti)

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
| `media_row_actions` | filter | Aggiunge azione "Converti in WebP/AVIF" |

## Autore

**Francesco Passeri**
- Sito: [francescopasseri.com](https://francescopasseri.com)
- Email: [info@francescopasseri.com](mailto:info@francescopasseri.com)
- GitHub: [github.com/franpass87](https://github.com/franpass87)
