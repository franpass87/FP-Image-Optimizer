<?php

declare(strict_types=1);

/**
 * Pulizia alla disinstallazione di FP Image Optimizer.
 *
 * Le immagini WebP/AVIF generate restano su disco (l'utente può eliminarle manualmente
 * dalla Media Library o via FTP). Rimuoviamo solo le opzioni.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('fp_imgopt_settings');
