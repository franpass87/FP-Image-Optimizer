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

wp_clear_scheduled_hook('fp_imgopt_bulk_cron');

delete_option('fp_imgopt_settings');
delete_option('fp_imgopt_failed_log');
delete_option('fp_imgopt_bulk_state');
