<?php

declare(strict_types=1);

namespace FP\ImgOpt\Core;

use FP\ImgOpt\Admin\Settings;
use FP\ImgOpt\Admin\SettingsPage;
use FP\ImgOpt\Frontend\PictureReplacer;
use FP\ImgOpt\Services\ImageConverter;
use FP\ImgOpt\Services\ImageDuplicatorOnSave;
use FP\ImgOpt\Services\ImageRenamer;

/**
 * Bootstrap del plugin FP Image Optimizer.
 *
 * @see https://github.com/franpass87/FP-Image-Optimizer
 */
final class Plugin {

    private static ?self $instance = null;

    private Settings $settings;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->settings = new Settings();
    }

    public function init(): void {
        $this->check_requirements();
        $this->register_hooks();
    }

    private function check_requirements(): void {
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            add_action('admin_notices', fn () => $this->render_requirement_notice(
                __('FP Image Optimizer richiede PHP 8.0 o superiore.', 'fp-imgopt')
            ));
            return;
        }
    }

    private function render_requirement_notice(string $message): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html($message)
        );
    }

    private function register_hooks(): void {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_fp_imgopt_convert', [$this, 'ajax_convert']);
        add_action('wp_ajax_fp_imgopt_bulk_convert', [$this, 'ajax_bulk_convert']);
        add_action('wp_ajax_fp_imgopt_stats', [$this, 'ajax_stats']);
        add_filter('media_row_actions', [$this, 'add_media_row_action'], 10, 2);

        if (!$this->settings->get('enabled', false)) {
            return;
        }

        $renamer  = new ImageRenamer($this->settings);
        $converter = new ImageConverter($this->settings);
        add_filter('wp_generate_attachment_metadata', [$renamer, 'on_generate_metadata'], 5, 2);
        add_filter('wp_generate_attachment_metadata', [$converter, 'on_generate_metadata'], 10, 2);
        add_filter('wp_generate_attachment_metadata', [$this, 'invalidate_stats_on_upload'], 999, 2);

        if ($this->settings->get('replace_content', true)) {
            $replacer = new PictureReplacer($this->settings);
            add_filter('the_content', [$replacer, 'replace_images'], 20);
            add_filter('post_thumbnail_html', [$replacer, 'replace_thumbnail'], 10, 5);
        }

        if ($this->settings->get('duplicate_on_save', false) || $this->settings->get('seo_attributes', false)) {
            $duplicator = new ImageDuplicatorOnSave($this->settings);
            add_action('save_post', [$duplicator, 'on_save_post'], 20, 1);
        }
    }

    /**
     * Aggiunge azione "Converti in WebP/AVIF" nella riga della Media Library.
     *
     * @param string[] $actions
     * @param WP_Post $post
     * @return string[]
     */
    public function add_media_row_action(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'attachment' || !current_user_can('manage_options')) {
            return $actions;
        }
        $mime = $post->post_mime_type ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            return $actions;
        }
        $url = wp_nonce_url(
            add_query_arg([
                'action'        => 'fp_imgopt_convert',
                'attachment_id' => $post->ID,
            ], admin_url('admin-ajax.php')),
            'fp_imgopt_convert_' . $post->ID
        );
        $actions['fp_imgopt_convert'] = sprintf(
            '<a href="%s" class="fp-imgopt-convert-link" data-id="%d">%s</a>',
            esc_url($url),
            $post->ID,
            esc_html__('Converti in WebP/AVIF', 'fp-imgopt')
        );
        return $actions;
    }

    public function register_admin_menu(): void {
        add_menu_page(
            __('FP Image Optimizer', 'fp-imgopt'),
            __('FP Image Optimizer', 'fp-imgopt'),
            'manage_options',
            'fp-imgopt',
            [new SettingsPage($this->settings), 'render'],
            'dashicons-images-alt2',
            58
        );
    }

    /**
     * Enqueue CSS/JS admin.
     *
     * @param string $hook Pagina admin corrente.
     */
    public function enqueue_admin_assets(string $hook): void {
        $is_our_page = str_contains($hook, 'fp-imgopt')
            || (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'fp-imgopt');

        if (!$is_our_page) {
            return;
        }

        wp_enqueue_style(
            'fp-imgopt-admin',
            FP_IMGOPT_URL . 'assets/css/admin.css',
            [],
            FP_IMGOPT_VERSION
        );

        wp_enqueue_script(
            'fp-imgopt-admin',
            FP_IMGOPT_URL . 'assets/js/admin.js',
            ['jquery'],
            FP_IMGOPT_VERSION,
            true
        );

        $stats = get_transient('fp_imgopt_stats');

        wp_localize_script('fp-imgopt-admin', 'fpImgOptConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('fp_imgopt_admin'),
            'stats'   => is_array($stats) ? $stats : null,
            'i18n'    => [
                'converting'   => __('Conversione in corso...', 'fp-imgopt'),
                'done'         => __('Completato.', 'fp-imgopt'),
                'error'        => __('Errore durante la conversione.', 'fp-imgopt'),
                'bulkRunning'  => __('Ottimizzazione bulk in corso...', 'fp-imgopt'),
                'bulkDone'     => __('Ottimizzazione bulk completata.', 'fp-imgopt'),
                'bulkNone'     => __('Nessuna immagine da ottimizzare.', 'fp-imgopt'),
                'statsRefresh' => __('Aggiorna statistiche', 'fp-imgopt'),
                'statsLoading' => __('Calcolo in corso...', 'fp-imgopt'),
            ],
        ]);
    }

    public function ajax_convert(): void {
        $attachment_id = absint($_REQUEST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => __('ID allegato mancante.', 'fp-imgopt')]);
            }
            wp_die(__('ID allegato mancante.', 'fp-imgopt'));
        }

        $nonce_ok = wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'fp_imgopt_convert_' . $attachment_id)
            || wp_verify_nonce($_REQUEST['nonce'] ?? '', 'fp_imgopt_admin');
        if (!$nonce_ok) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => __('Errore di sicurezza.', 'fp-imgopt')], 403);
            }
            wp_die(__('Errore di sicurezza.', 'fp-imgopt'));
        }

        if (!current_user_can('manage_options')) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-imgopt')], 403);
            }
            wp_die(__('Permessi insufficienti.', 'fp-imgopt'));
        }

        // Non rinomina mai immagini esistenti: solo conversione. Il rename avviene solo su nuovi upload.
        $converter = new ImageConverter($this->settings);
        $result    = $converter->convert_attachment($attachment_id);

        if (is_wp_error($result)) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }
            wp_die(esc_html($result->get_error_message()));
        }

        do_action('fp_imgopt_attachment_converted', $attachment_id, $result);
        $this->invalidate_stats_cache();

        if (wp_doing_ajax()) {
            wp_send_json_success($result);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('upload.php'));
        exit;
    }

    /**
     * Converte in bulk le immagini esistenti in Media Library.
     *
     * Usa la stessa pipeline sicura di conversione (`ImageConverter::convert_attachment`):
     * gli originali non vengono modificati e file corrotti vengono scartati.
     */
    public function ajax_bulk_convert(): void {
        $nonce_ok = wp_verify_nonce($_REQUEST['nonce'] ?? '', 'fp_imgopt_admin');
        if (!$nonce_ok) {
            wp_send_json_error(['message' => __('Errore di sicurezza.', 'fp-imgopt')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-imgopt')], 403);
        }

        $offset = max(0, absint($_REQUEST['offset'] ?? 0));
        $limit  = min(50, max(1, absint($_REQUEST['limit'] ?? 20)));

        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        if (empty($ids)) {
            wp_send_json_success([
                'processed' => 0,
                'converted' => 0,
                'failed'    => 0,
                'has_more'  => false,
                'next'      => $offset,
            ]);
        }

        $converter = new ImageConverter($this->settings);
        $converted = 0;
        $failed    = 0;

        foreach ($ids as $attachment_id) {
            $result = $converter->convert_attachment((int) $attachment_id);
            if (is_wp_error($result)) {
                $failed++;
                continue;
            }
            if (!empty($result['webp']) || !empty($result['avif'])) {
                $converted++;
                do_action('fp_imgopt_attachment_converted', $attachment_id, $result);
            }
        }

        $this->invalidate_stats_cache();

        wp_send_json_success([
            'processed' => count($ids),
            'converted' => $converted,
            'failed'    => $failed,
            'has_more'  => count($ids) === $limit,
            'next'      => $offset + count($ids),
        ]);
    }

    /**
     * AJAX: calcola e restituisce le statistiche di conversione.
     */
    public function ajax_stats(): void {
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'fp_imgopt_admin')) {
            wp_send_json_error(['message' => __('Errore di sicurezza.', 'fp-imgopt')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-imgopt')], 403);
        }

        $stats = $this->compute_stats();
        set_transient('fp_imgopt_stats', $stats, HOUR_IN_SECONDS);

        wp_send_json_success($stats);
    }

    /**
     * Calcola statistiche: immagini convertite, varianti, risparmio stimato.
     *
     * @return array{total_images: int, with_variants: int, webp_count: int, avif_count: int, saved_mb: float}
     */
    public function compute_stats(): array {
        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $with_variants = 0;
        $webp_count   = 0;
        $avif_count   = 0;
        $saved_bytes  = 0.0;

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return [
                'total_images'  => count($ids),
                'with_variants' => 0,
                'webp_count'    => 0,
                'avif_count'    => 0,
                'saved_mb'      => 0.0,
            ];
        }

        $base_dir = trailingslashit($upload_dir['basedir']);

        foreach ($ids as $attachment_id) {
            $path = get_attached_file((int) $attachment_id);
            if (!$path || !is_file($path) || strpos($path, $base_dir) !== 0) {
                continue;
            }

            $path_info = pathinfo($path);
            $dir       = $path_info['dirname'] . '/';
            $filename  = $path_info['filename'];
            $ext       = strtolower($path_info['extension'] ?? '');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                continue;
            }

            $orig_size = (float) filesize($path);
            $has_any   = false;
            $best_save = 0.0;

            $webp_path = $dir . $filename . '.webp';
            $webp_size = is_file($webp_path) && filesize($webp_path) > 100 ? filesize($webp_path) : null;
            if ($webp_size !== null) {
                $webp_count++;
                $has_any   = true;
                $best_save = max($best_save, $orig_size - $webp_size);
            }

            $avif_path = $dir . $filename . '.avif';
            $avif_size = is_file($avif_path) && filesize($avif_path) > 100 ? filesize($avif_path) : null;
            if ($avif_size !== null) {
                $avif_count++;
                $has_any   = true;
                $best_save = max($best_save, $orig_size - $avif_size);
            }
            $saved_bytes += $best_save;

            $meta = wp_get_attachment_metadata($attachment_id);
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $s) {
                    $sf = $s['file'] ?? '';
                    if ($sf === '') {
                        continue;
                    }
                    $sf        = basename($sf);
                    $size_path = $dir . $sf;
                    if (!is_file($size_path)) {
                        continue;
                    }
                    $path_info_s = pathinfo($size_path);
                    $fn_s        = $path_info_s['filename'] ?? '';
                    if ($fn_s === '') {
                        continue;
                    }
                    $orig_s   = (float) filesize($size_path);
                    $best_s   = 0.0;
                    $wp_s     = $dir . $fn_s . '.webp';
                    if (is_file($wp_s) && filesize($wp_s) > 100) {
                        $webp_count++;
                        $best_s = max($best_s, $orig_s - filesize($wp_s));
                    }
                    $av_s = $dir . $fn_s . '.avif';
                    if (is_file($av_s) && filesize($av_s) > 100) {
                        $avif_count++;
                        $best_s = max($best_s, $orig_s - filesize($av_s));
                    }
                    $saved_bytes += $best_s;
                }
            }

            if ($has_any) {
                $with_variants++;
            }
        }

        return [
            'total_images'  => count($ids),
            'with_variants' => $with_variants,
            'webp_count'    => $webp_count,
            'avif_count'    => $avif_count,
            'saved_mb'      => round($saved_bytes / (1024 * 1024), 2),
        ];
    }

    /**
     * Invalida la cache delle statistiche (dopo conversione).
     */
    public function invalidate_stats_cache(): void {
        delete_transient('fp_imgopt_stats');
    }

    /**
     * Hook su wp_generate_attachment_metadata: invalida cache stats dopo upload.
     *
     * @param array<string, mixed> $metadata
     * @param int $attachment_id
     * @return array<string, mixed>
     */
    public function invalidate_stats_on_upload(array $metadata, int $attachment_id): array {
        $this->invalidate_stats_cache();
        return $metadata;
    }

    public function get_settings(): Settings {
        return $this->settings;
    }
}
