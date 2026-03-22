<?php

declare(strict_types=1);

namespace FP\ImgOpt\Admin;

use FP\ImgOpt\Services\ImageConverter;

/**
 * Pagina impostazioni admin FP Image Optimizer.
 */
final class SettingsPage {

    private const MENU_SLUG = 'fp-imgopt';

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function render(): void {
        if (isset($_POST['fp_imgopt_save']) && check_admin_referer('fp_imgopt_save_settings', 'fp_imgopt_nonce')) {
            if (current_user_can('manage_options')) {
                $this->save_settings();
            }
        }

        $converter = new ImageConverter($this->settings);
        $webp_ok   = $converter->supports_webp();
        $avif_ok   = $converter->supports_avif();

        $data = $this->settings->all();
        ?>
<div class="wrap fpimgopt-admin-page">

    <h1 class="screen-reader-text"><?php echo esc_html__('FP Image Optimizer', 'fp-imgopt'); ?></h1>

    <div class="fpimgopt-page-header">
        <div class="fpimgopt-page-header-content">
            <h2 class="fpimgopt-page-header-title" aria-hidden="true">
                <span class="dashicons dashicons-images-alt2"></span>
                <?php echo esc_html__('FP Image Optimizer', 'fp-imgopt'); ?>
            </h2>
            <p><?php echo esc_html__('Converte le immagini in WebP e AVIF per ridurre il peso e migliorare le performance. Le foto originali non vengono mai modificate.', 'fp-imgopt'); ?></p>
        </div>
        <span class="fpimgopt-page-header-badge">v<?php echo esc_html(FP_IMGOPT_VERSION); ?></span>
    </div>

    <div class="fpimgopt-status-bar">
        <span class="fpimgopt-status-pill <?php echo $webp_ok ? 'is-active' : 'is-missing'; ?>">
            <span class="dot"></span>
            <?php echo $webp_ok ? esc_html__('WebP supportato', 'fp-imgopt') : esc_html__('WebP non disponibile', 'fp-imgopt'); ?>
        </span>
        <span class="fpimgopt-status-pill <?php echo $avif_ok ? 'is-active' : 'is-missing'; ?>">
            <span class="dot"></span>
            <?php echo $avif_ok ? esc_html__('AVIF supportato', 'fp-imgopt') : esc_html__('AVIF non disponibile', 'fp-imgopt'); ?>
        </span>
    </div>

    <?php
    $stats = get_transient('fp_imgopt_stats');
    $stats = is_array($stats) ? $stats : null;
    ?>
    <div class="fpimgopt-card fpimgopt-stats-card">
        <div class="fpimgopt-card-header">
            <div class="fpimgopt-card-header-left">
                <span class="dashicons dashicons-chart-bar"></span>
                <h2><?php echo esc_html__('Statistiche', 'fp-imgopt'); ?></h2>
            </div>
            <button type="button" id="fpimgopt-stats-refresh" class="fpimgopt-btn fpimgopt-btn-secondary fpimgopt-btn-sm">
                <span class="dashicons dashicons-update"></span>
                <?php echo esc_html__('Aggiorna statistiche', 'fp-imgopt'); ?>
            </button>
        </div>
        <div class="fpimgopt-card-body">
            <div class="fpimgopt-stats-grid">
                <div class="fpimgopt-stat-item">
                    <span class="fpimgopt-stat-value" id="fpimgopt-stat-total"><?php echo $stats ? (int) $stats['total_images'] : '—'; ?></span>
                    <span class="fpimgopt-stat-label"><?php echo esc_html__('Immagini totali', 'fp-imgopt'); ?></span>
                </div>
                <div class="fpimgopt-stat-item">
                    <span class="fpimgopt-stat-value" id="fpimgopt-stat-converted"><?php echo $stats ? (int) $stats['with_variants'] : '—'; ?></span>
                    <span class="fpimgopt-stat-label"><?php echo esc_html__('Con varianti', 'fp-imgopt'); ?></span>
                </div>
                <div class="fpimgopt-stat-item">
                    <span class="fpimgopt-stat-value" id="fpimgopt-stat-webp"><?php echo $stats ? (int) $stats['webp_count'] : '—'; ?></span>
                    <span class="fpimgopt-stat-label"><?php echo esc_html__('File WebP', 'fp-imgopt'); ?></span>
                </div>
                <div class="fpimgopt-stat-item">
                    <span class="fpimgopt-stat-value" id="fpimgopt-stat-avif"><?php echo $stats ? (int) $stats['avif_count'] : '—'; ?></span>
                    <span class="fpimgopt-stat-label"><?php echo esc_html__('File AVIF', 'fp-imgopt'); ?></span>
                </div>
                <div class="fpimgopt-stat-item fpimgopt-stat-highlight">
                    <span class="fpimgopt-stat-value" id="fpimgopt-stat-saved"><?php echo $stats ? esc_html((string) $stats['saved_mb'] . ' MB') : '—'; ?></span>
                    <span class="fpimgopt-stat-label"><?php echo esc_html__('Risparmio stimato', 'fp-imgopt'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field('fp_imgopt_save_settings', 'fp_imgopt_nonce'); ?>

        <div class="fpimgopt-card">
            <div class="fpimgopt-card-header">
                <div class="fpimgopt-card-header-left">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <h2><?php echo esc_html__('Impostazioni', 'fp-imgopt'); ?></h2>
                </div>
                <span class="fpimgopt-badge <?php echo !empty($data['enabled']) ? 'fpimgopt-badge-success' : 'fpimgopt-badge-neutral'; ?>">
                    <?php echo !empty($data['enabled']) ? '&#10003; ' . esc_html__('Attivo', 'fp-imgopt') : esc_html__('Disattivo', 'fp-imgopt'); ?>
                </span>
            </div>
            <div class="fpimgopt-card-body">
                <p class="description"><?php echo esc_html__('Abilita la conversione automatica e la sostituzione delle immagini nel contenuto.', 'fp-imgopt'); ?></p>

                <div class="fpimgopt-toggle-row">
                    <div class="fpimgopt-toggle-info">
                        <strong><?php echo esc_html__('Abilita ottimizzazione', 'fp-imgopt'); ?></strong>
                        <span><?php echo esc_html__('Conversione WebP/AVIF e sostituzione nel frontend.', 'fp-imgopt'); ?></span>
                    </div>
                    <label class="fpimgopt-toggle">
                        <input type="checkbox" name="fp_imgopt_settings[enabled]" value="1" <?php checked(!empty($data['enabled'])); ?>>
                        <span class="fpimgopt-toggle-slider"></span>
                    </label>
                </div>

                <div class="fpimgopt-toggle-row">
                    <div class="fpimgopt-toggle-info">
                        <strong><?php echo esc_html__('Conversione al caricamento', 'fp-imgopt'); ?></strong>
                        <span><?php echo esc_html__('Genera WebP/AVIF automaticamente quando carichi immagini.', 'fp-imgopt'); ?></span>
                    </div>
                    <label class="fpimgopt-toggle">
                        <input type="checkbox" name="fp_imgopt_settings[on_upload]" value="1" <?php checked(!empty($data['on_upload'])); ?>>
                        <span class="fpimgopt-toggle-slider"></span>
                    </label>
                </div>

                <div class="fpimgopt-toggle-row">
                    <div class="fpimgopt-toggle-info">
                        <strong><?php echo esc_html__('Sostituzione nel contenuto', 'fp-imgopt'); ?></strong>
                        <span><?php echo esc_html__('Usa tag &lt;picture&gt; per servire WebP/AVIF ai browser compatibili.', 'fp-imgopt'); ?></span>
                    </div>
                    <label class="fpimgopt-toggle">
                        <input type="checkbox" name="fp_imgopt_settings[replace_content]" value="1" <?php checked(!empty($data['replace_content'])); ?>>
                        <span class="fpimgopt-toggle-slider"></span>
                    </label>
                </div>

                <div class="fpimgopt-toggle-row">
                    <div class="fpimgopt-toggle-info">
                        <strong><?php echo esc_html__('Rinomina all\'upload', 'fp-imgopt'); ?></strong>
                        <span><?php echo esc_html__('Solo nuovi upload: formato nome-sito-slug-id (es. mio-sito-media-456.jpg).', 'fp-imgopt'); ?></span>
                    </div>
                    <label class="fpimgopt-toggle">
                        <input type="checkbox" name="fp_imgopt_settings[rename_files]" value="1" <?php checked(!empty($data['rename_files'])); ?>>
                        <span class="fpimgopt-toggle-slider"></span>
                    </label>
                </div>

                <div class="fpimgopt-toggle-row">
                    <div class="fpimgopt-toggle-info">
                        <strong><?php echo esc_html__('Duplicato al salvataggio', 'fp-imgopt'); ?></strong>
                        <span><?php echo esc_html__('Quando salvi un articolo o una pagina, crea una copia di ogni immagine con nome contestuale (es. mio-sito-contatti-456.jpg) e aggiorna il contenuto.', 'fp-imgopt'); ?></span>
                    </div>
                    <label class="fpimgopt-toggle">
                        <input type="checkbox" name="fp_imgopt_settings[duplicate_on_save]" value="1" <?php checked(!empty($data['duplicate_on_save'])); ?>>
                        <span class="fpimgopt-toggle-slider"></span>
                    </label>
                </div>

                <div class="fpimgopt-toggle-row">
                    <div class="fpimgopt-toggle-info">
                        <strong><?php echo esc_html__('Attributi SEO (alt, title, caption)', 'fp-imgopt'); ?></strong>
                        <span><?php echo esc_html__('Genera automaticamente alt text, title e caption dall\'immagine contestuale (titolo pagina, slug). Solo se vuoti.', 'fp-imgopt'); ?></span>
                    </div>
                    <label class="fpimgopt-toggle">
                        <input type="checkbox" name="fp_imgopt_settings[seo_attributes]" value="1" <?php checked(!empty($data['seo_attributes'])); ?>>
                        <span class="fpimgopt-toggle-slider"></span>
                    </label>
                </div>

                <div class="fpimgopt-fields-grid" style="margin-top: 20px;">
                    <div class="fpimgopt-toggle-row">
                        <div class="fpimgopt-toggle-info">
                            <strong><?php echo esc_html__('Formato WebP', 'fp-imgopt'); ?></strong>
                            <span><?php echo esc_html__('Genera varianti WebP (compatibilità ampia).', 'fp-imgopt'); ?></span>
                        </div>
                        <label class="fpimgopt-toggle">
                            <input type="checkbox" name="fp_imgopt_settings[format_webp]" value="1" <?php checked(!empty($data['format_webp'])); ?> <?php disabled(!$webp_ok); ?>>
                            <span class="fpimgopt-toggle-slider"></span>
                        </label>
                    </div>
                    <?php if ($webp_ok) : ?>
                    <div class="fpimgopt-field">
                        <label for="fp_imgopt_quality_webp"><?php echo esc_html__('Qualità WebP (1-100)', 'fp-imgopt'); ?></label>
                        <input type="number" id="fp_imgopt_quality_webp" name="fp_imgopt_settings[quality_webp]" value="<?php echo esc_attr((string) $data['quality_webp']); ?>" min="1" max="100" class="small-text">
                    </div>
                    <?php endif; ?>

                    <div class="fpimgopt-toggle-row">
                        <div class="fpimgopt-toggle-info">
                            <strong><?php echo esc_html__('Formato AVIF', 'fp-imgopt'); ?></strong>
                            <span><?php echo esc_html__('Genera varianti AVIF (compressione migliore, browser recenti).', 'fp-imgopt'); ?></span>
                        </div>
                        <label class="fpimgopt-toggle">
                            <input type="checkbox" name="fp_imgopt_settings[format_avif]" value="1" <?php checked(!empty($data['format_avif'])); ?> <?php disabled(!$avif_ok); ?>>
                            <span class="fpimgopt-toggle-slider"></span>
                        </label>
                    </div>
                    <?php if ($avif_ok) : ?>
                    <div class="fpimgopt-field">
                        <label for="fp_imgopt_quality_avif"><?php echo esc_html__('Qualità AVIF (1-100)', 'fp-imgopt'); ?></label>
                        <input type="number" id="fp_imgopt_quality_avif" name="fp_imgopt_settings[quality_avif]" value="<?php echo esc_attr((string) $data['quality_avif']); ?>" min="1" max="100" class="small-text">
                    </div>
                    <?php endif; ?>
                </div>

                <p style="margin-top: 24px;">
                    <button type="submit" name="fp_imgopt_save" class="fpimgopt-btn fpimgopt-btn-primary">
                        <span class="dashicons dashicons-saved"></span>
                        <?php echo esc_html__('Salva impostazioni', 'fp-imgopt'); ?>
                    </button>
                </p>
            </div>
        </div>
    </form>

    <div class="fpimgopt-card">
        <div class="fpimgopt-card-header">
            <div class="fpimgopt-card-header-left">
                <span class="dashicons dashicons-format-gallery"></span>
                <h2><?php echo esc_html__('Immagini esistenti', 'fp-imgopt'); ?></h2>
            </div>
        </div>
        <div class="fpimgopt-card-body">
            <p class="description"><?php echo esc_html__('Per convertire le immagini già presenti: vai su Media → Libreria, passa con il mouse su un\'immagine e clicca su "Converti in WebP/AVIF". Le immagini originali non vengono mai modificate o eliminate.', 'fp-imgopt'); ?></p>
            <p><?php echo esc_html__('Le nuove immagini caricate verranno convertite automaticamente se "Conversione al caricamento" è attiva.', 'fp-imgopt'); ?></p>
            <div class="fpimgopt-bulk-box">
                <button type="button" id="fpimgopt-bulk-start" class="fpimgopt-btn fpimgopt-btn-primary">
                    <span class="dashicons dashicons-update"></span>
                    <?php echo esc_html__('Avvia Bulk Optimizer sicuro', 'fp-imgopt'); ?>
                </button>
                <p id="fpimgopt-bulk-status" class="fpimgopt-bulk-status">
                    <?php echo esc_html__('Converte in batch le immagini già presenti senza modificare gli originali.', 'fp-imgopt'); ?>
                </p>
            </div>
        </div>
    </div>

</div>
        <?php
    }

    private function save_settings(): void {
        $raw = $_POST['fp_imgopt_settings'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $this->settings->save($raw);
        add_settings_error(
            'fp_imgopt',
            'saved',
            __('Impostazioni salvate.', 'fp-imgopt'),
            'success'
        );
    }
}
