=== FP Image Optimizer ===

Contributors: franpass87
Tags: images, webp, avif, optimization, performance
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.6.0
License: Proprietary

Converte le immagini della Media Library in WebP e AVIF per ridurre il peso e migliorare le performance.

== Description ==

FP Image Optimizer genera automaticamente varianti WebP e AVIF delle immagini caricate nella Media Library. I browser compatibili ricevono i formati moderni tramite il tag picture, con fallback al formato originale.

= Funzionalità =

* Conversione automatica al caricamento
* Sostituzione nel contenuto con tag picture
* Conversione manuale per immagini esistenti (Media Library)
* Configurabile: formati, qualità, sostituzione frontend

= Requisiti =

* PHP 8.0+
* GD con WebP o Imagick
* AVIF: GD con libavif (PHP 8.1+) o Imagick

== Changelog ==

= 1.6.0 =
* Stato bulk in background (polling, messaggio completamento)
* Bulk "solo mancanti" (skip già convertite)
* Limite statistiche (max 2000 immagini)
* Aiuto esclusioni (lista post type)
* Riprova conversioni fallite
* Icone colonna Media
* Supporto WooCommerce galleria prodotti
* Esclusione thumbnail piccole (skip_min_dimension)

= 1.5.0 =
* Rimozione varianti WebP/AVIF in blocco
* Esclusioni: post type e meta "Non ottimizzare" per post/pagina
* Bulk in background (cron)
* Log conversioni fallite (ultimi 50)
* Colonna WebP/AVIF in Media Library

= 1.4.0 =
* srcset responsive nel picture (WebP/AVIF per tutte le dimensioni)
* Hook e filtri per estensibilità (skip, variant_urls, picture_html, attachment_converted)
* Dashboard statistiche: immagini convertite, file WebP/AVIF, risparmio MB

= 1.3.3 =
* Fix path validation in Renamer, Converter, Duplicator (basename, realpath, ..)

= 1.3.2 =
* Fix path traversal in PictureReplacer

= 1.3.1 =
* Fix rollback ImageRenamer su fallimento dimensioni
* Fix liberazione risorse ImageConverter
* Validazione copie ImageDuplicatorOnSave

= 1.3.0 =
* Attributi SEO (alt, title, caption) generati dal contesto
* Toggle "Attributi SEO" nelle impostazioni

= 1.2.0 =
* Duplicato al salvataggio post/pagina
* Due rinominazioni: upload + salvataggio

= 1.1.2 =
* WebP/AVIF: validazione file, mai servire corrotti
* Fallback originale se varianti non valide

= 1.1.1 =
* Sicurezza: immagini esistenti mai rinominate
* Rollback su errore rename
* Fix URL per CDN

= 1.1.0 =
* Rinominamento file immagine (nome-sito-slug-pagina-id)
* Toggle nelle impostazioni
* Aggiornamento riferimenti nel contenuto

= 1.0.0 =
* Release iniziale
