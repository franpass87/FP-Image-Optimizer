<?php

declare(strict_types=1);

namespace FP\ImgOpt\Core;

use FP\ImgOpt\Admin\RenameByPostPage;
use FP\ImgOpt\Admin\Settings;
use FP\ImgOpt\Admin\SettingsPage;
use FP\ImgOpt\Frontend\PictureReplacer;
use FP\ImgOpt\Services\FailedLog;
use FP\ImgOpt\Services\ImageConverter;
use FP\ImgOpt\Services\ContentImageExtractor;
use FP\ImgOpt\Services\ImageDuplicatorOnSave;
use FP\ImgOpt\Services\ImageRenamer;
use FP\ImgOpt\Services\VariantRemover;

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
        add_action('wp_ajax_fp_imgopt_remove_variants', [$this, 'ajax_remove_variants']);
        add_action('wp_ajax_fp_imgopt_clear_log', [$this, 'ajax_clear_log']);
        add_action('wp_ajax_fp_imgopt_bulk_start_background', [$this, 'ajax_bulk_start_background']);
        add_action('wp_ajax_fp_imgopt_bulk_state', [$this, 'ajax_bulk_state']);
        add_action('wp_ajax_fp_imgopt_retry_failed', [$this, 'ajax_retry_failed']);
        add_action('wp_ajax_fp_imgopt_rename_post_images', [$this, 'ajax_rename_post_images']);
        add_filter('media_row_actions', [$this, 'add_media_row_action'], 10, 2);
        add_filter('manage_media_columns', [$this, 'add_media_column']);
        add_action('manage_media_custom_column', [$this, 'render_media_column'], 10, 2);
        add_action('add_meta_boxes', [$this, 'add_skip_meta_box']);
        add_action('save_post', [$this, 'save_skip_meta'], 10, 2);

        add_action('fp_imgopt_bulk_cron', [$this, 'run_bulk_cron']);
        add_action('init', [$this, 'maybe_schedule_bulk_cron']);

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
            if (class_exists('WooCommerce')) {
                add_filter('woocommerce_single_product_image_thumbnail_html', [$replacer, 'replace_woocommerce_image'], 10, 2);
            }
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
        add_submenu_page(
            'fp-imgopt',
            __('Rinomina per pagina/articolo', 'fp-imgopt'),
            __('Rinomina per contenuto', 'fp-imgopt'),
            'manage_options',
            RenameByPostPage::PAGE_SLUG,
            [new RenameByPostPage($this->settings), 'render']
        );
    }

    /**
     * Enqueue CSS/JS admin.
     *
     * @param string $hook Pagina admin corrente.
     */
    public function enqueue_admin_assets(string $hook): void {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        $is_our_page = str_contains($hook, 'fp-imgopt')
            || in_array($page, ['fp-imgopt', RenameByPostPage::PAGE_SLUG], true);
        $is_media   = $hook === 'upload.php';

        if (!$is_our_page && !$is_media) {
            return;
        }

        if ($is_media) {
            wp_enqueue_style('fp-imgopt-admin', FP_IMGOPT_URL . 'assets/css/admin.css', [], FP_IMGOPT_VERSION);
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
                'statsRefresh'     => __('Aggiorna statistiche', 'fp-imgopt'),
                'statsLoading'     => __('Calcolo in corso...', 'fp-imgopt'),
                'removeConfirm'    => __('Eliminare tutte le varianti WebP/AVIF? Le immagini originali restano intatte.', 'fp-imgopt'),
                'removeSuccess'    => __('Varianti rimosse.', 'fp-imgopt'),
                'bulkBackgroundOk' => __('Bulk avviato in background. Esegue un batch ogni minuto.', 'fp-imgopt'),
                'renameConfirm'    => __('Rinominare le immagini di questo contenuto?', 'fp-imgopt'),
                'renameSuccess'    => __('Immagini rinominate.', 'fp-imgopt'),
                'renameError'      => __('Errore durante il rinomina.', 'fp-imgopt'),
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
            FailedLog::add($attachment_id, $result->get_error_message());
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

        $offset       = max(0, absint($_REQUEST['offset'] ?? 0));
        $limit        = min(50, max(1, absint($_REQUEST['limit'] ?? 20)));
        $only_missing = !empty($_REQUEST['only_missing']);

        $fetch_limit = $only_missing ? 100 : $limit;
        $ids         = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => $fetch_limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        if ($only_missing && !empty($ids)) {
            $ids = array_values(array_filter($ids, fn (int $id) => !$this->attachment_has_variants($id)));
            $ids = array_slice($ids, 0, $limit);
        }

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
                FailedLog::add((int) $attachment_id, $result->get_error_message());
                $failed++;
                continue;
            }
            if (!empty($result['webp']) || !empty($result['avif'])) {
                $converted++;
                do_action('fp_imgopt_attachment_converted', $attachment_id, $result);
            }
        }

        $this->invalidate_stats_cache();

        $consumed = $only_missing ? $fetch_limit : count($ids);
        wp_send_json_success([
            'processed' => count($ids),
            'converted' => $converted,
            'failed'    => $failed,
            'has_more'  => $consumed === $fetch_limit,
            'next'      => $offset + $consumed,
        ]);
    }

    /**
     * Verifica se un attachment ha già varianti WebP o AVIF.
     */
    public function attachment_has_variants(int $attachment_id): bool {
        $path = get_attached_file($attachment_id);
        if (!$path || !is_file($path)) {
            return false;
        }
        $path_info = pathinfo($path);
        $dir       = $path_info['dirname'] . '/';
        $filename  = $path_info['filename'];
        $ext       = strtolower($path_info['extension'] ?? '');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return false;
        }
        $has_webp = $this->settings->get('format_webp', true)
            && is_file($dir . $filename . '.webp')
            && filesize($dir . $filename . '.webp') > 100;
        $has_avif = $this->settings->get('format_avif', true)
            && is_file($dir . $filename . '.avif')
            && filesize($dir . $filename . '.avif') > 100;
        return $has_webp || $has_avif;
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
        $max_scan = 2000;
        $ids      = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => $max_scan,
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

        $total = count($ids);
        return [
            'total_images'  => $total,
            'with_variants' => $with_variants,
            'webp_count'    => $webp_count,
            'avif_count'    => $avif_count,
            'saved_mb'      => round($saved_bytes / (1024 * 1024), 2),
            'capped'        => $total >= $max_scan,
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

    /**
     * AJAX: rimuove tutte le varianti WebP/AVIF.
     */
    public function ajax_remove_variants(): void {
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'fp_imgopt_admin')) {
            wp_send_json_error(['message' => __('Errore di sicurezza.', 'fp-imgopt')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-imgopt')], 403);
        }

        $remover = new VariantRemover();
        $result  = $remover->remove_all_variants();
        $this->invalidate_stats_cache();

        wp_send_json_success($result);
    }

    /**
     * AJAX: svuota il log errori.
     */
    public function ajax_clear_log(): void {
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'fp_imgopt_admin')) {
            wp_send_json_error(['message' => __('Errore di sicurezza.', 'fp-imgopt')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-imgopt')], 403);
        }

        FailedLog::clear();
        wp_send_json_success([]);
    }

    /**
     * AJAX: avvia il bulk optimizer in background (cron).
     */
    public function ajax_bulk_start_background(): void {
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'fp_imgopt_admin')) {
            wp_send_json_error(['message' => __('Errore di sicurezza.', 'fp-imgopt')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-imgopt')], 403);
        }

        $only_missing = !empty($_REQUEST['only_missing']);
        update_option('fp_imgopt_bulk_state', [
            'offset'       => 0,
            'processed'    => 0,
            'converted'    => 0,
            'failed'       => 0,
            'only_missing' => $only_missing,
        ]);
        wp_schedule_single_event(time() + 10, 'fp_imgopt_bulk_cron');

        wp_send_json_success(['message' => __('Bulk avviato in background.', 'fp-imgopt'), 'state' => get_option('fp_imgopt_bulk_state')]);
    }

    /**
     * AJAX: restituisce lo stato del bulk in background.
     */
    public function ajax_bulk_state(): void {
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'fp_imgopt_admin')) {
            wp_send_json_error(['message' => __('Errore di sicurezza.', 'fp-imgopt')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-imgopt')], 403);
        }

        $state = get_option('fp_imgopt_bulk_state');
        wp_send_json_success(is_array($state) ? $state : null);
    }

    /**
     * AJAX: ritenta le conversioni degli ultimi errori nel log.
     */
    public function ajax_retry_failed(): void {
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'fp_imgopt_admin')) {
            wp_send_json_error(['message' => __('Errore di sicurezza.', 'fp-imgopt')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-imgopt')], 403);
        }

        $log    = FailedLog::get();
        $ids    = array_unique(array_map(fn (array $e) => (int) $e['attachment_id'], $log));
        $retried = 0;
        $converter = new ImageConverter($this->settings);

        foreach ($ids as $attachment_id) {
            if ($attachment_id <= 0) {
                continue;
            }
            $result = $converter->convert_attachment($attachment_id);
            if (!is_wp_error($result) && (!empty($result['webp']) || !empty($result['avif']))) {
                $retried++;
                do_action('fp_imgopt_attachment_converted', $attachment_id, $result);
            }
        }

        FailedLog::clear();
        $this->invalidate_stats_cache();
        wp_send_json_success(['retried' => $retried, 'count' => count($ids)]);
    }

    /**
     * AJAX: rinomina le immagini contenute in un post/pagina.
     */
    public function ajax_rename_post_images(): void {
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'fp_imgopt_admin')) {
            wp_send_json_error(['message' => __('Errore di sicurezza.', 'fp-imgopt')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti.', 'fp-imgopt')], 403);
        }

        $post_id = absint($_REQUEST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => __('ID post mancante.', 'fp-imgopt')]);
        }

        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true)) {
            wp_send_json_error(['message' => __('Post non valido.', 'fp-imgopt')]);
        }

        $ids = ContentImageExtractor::get_attachment_ids_from_post($post_id);
        $to_rename = [];
        foreach ($ids as $aid) {
            $path = get_attached_file($aid);
            if ($path && is_file($path)) {
                $bn = pathinfo($path, PATHINFO_FILENAME);
                if (!(bool) preg_match('/^.+-' . $aid . '$/', $bn)) {
                    $to_rename[] = $aid;
                }
            }
        }

        $renamer = new ImageRenamer($this->settings);
        $renamed = 0;
        $errors  = [];

        foreach ($to_rename as $aid) {
            $result = $renamer->rename_attachment_for_post($aid, $post_id);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } elseif (!empty($result['renamed'])) {
                $renamed++;
            }
        }

        wp_send_json_success([
            'renamed' => $renamed,
            'total'   => count($to_rename),
            'errors'  => $errors,
        ]);
    }

    /**
     * Colonna Media Library: WebP/AVIF.
     *
     * @param string[] $columns
     * @return string[]
     */
    public function add_media_column(array $columns): array {
        $columns['fp_imgopt_variants'] = 'WebP/AVIF';
        return $columns;
    }

    /**
     * Render della colonna WebP/AVIF.
     */
    public function render_media_column(string $column_name, int $post_id): void {
        if ($column_name !== 'fp_imgopt_variants') {
            return;
        }
        $path = get_attached_file($post_id);
        if (!$path || !is_file($path)) {
            echo '—';
            return;
        }
        $path_info = pathinfo($path);
        $ext       = strtolower($path_info['extension'] ?? '');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            echo '—';
            return;
        }
        $dir      = $path_info['dirname'] . '/';
        $filename = $path_info['filename'];
        $has_webp = is_file($dir . $filename . '.webp') && filesize($dir . $filename . '.webp') > 100;
        $has_avif = is_file($dir . $filename . '.avif') && filesize($dir . $filename . '.avif') > 100;
        if ($has_webp || $has_avif) {
            $labels = array_filter([$has_webp ? 'WebP' : '', $has_avif ? 'AVIF' : '']);
            echo '<span class="fpimgopt-media-badge" title="' . esc_attr(implode(', ', $labels)) . '">';
            echo '<span class="dashicons dashicons-yes-alt" style="color:var(--fpdms-success,#00a32a);font-size:16px;width:16px;height:16px;"></span> ';
            echo esc_html(implode(', ', $labels));
            echo '</span>';
        } else {
            echo '—';
        }
    }

    /**
     * Meta box "Non ottimizzare" su post/pagina.
     */
    public function add_skip_meta_box(): void {
        add_meta_box(
            'fp_imgopt_skip',
            __('FP Image Optimizer', 'fp-imgopt'),
            [$this, 'render_skip_meta_box'],
            ['post', 'page'],
            'side'
        );
    }

    /**
     * Render del meta box skip.
     */
    public function render_skip_meta_box(\WP_Post $post): void {
        wp_nonce_field('fp_imgopt_skip_meta', 'fp_imgopt_skip_nonce');
        $checked = get_post_meta($post->ID, 'fp_imgopt_skip', true);
        ?>
        <p>
            <label>
                <input type="checkbox" name="fp_imgopt_skip" value="1" <?php checked($checked); ?>>
                <?php echo esc_html__('Non ottimizzare immagini per questo contenuto', 'fp-imgopt'); ?>
            </label>
        </p>
        <?php
    }

    /**
     * Salva meta fp_imgopt_skip.
     *
     * @param int $post_id
     * @param \WP_Post|null $post
     */
    public function save_skip_meta(int $post_id, $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST['fp_imgopt_skip_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fp_imgopt_skip_nonce'])), 'fp_imgopt_skip_meta')) {
            return;
        }
        $post = $post ?? get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $val = isset($_POST['fp_imgopt_skip']) && $_POST['fp_imgopt_skip'] === '1' ? '1' : '';
        update_post_meta($post_id, 'fp_imgopt_skip', $val);
    }

    /**
     * Cron: esegue un batch del bulk optimizer.
     */
    public function run_bulk_cron(): void {
        $state = get_option('fp_imgopt_bulk_state');
        if (!is_array($state)) {
            return;
        }

        $offset       = (int) ($state['offset'] ?? 0);
        $limit        = 20;
        $only_missing = !empty($state['only_missing']);
        $fetch_limit  = $only_missing ? 100 : $limit;
        $converter    = new ImageConverter($this->settings);

        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => $fetch_limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        if ($only_missing && !empty($ids)) {
            $ids = array_values(array_filter($ids, fn (int $id) => !$this->attachment_has_variants($id)));
            $ids = array_slice($ids, 0, $limit);
        }

        $processed = (int) ($state['processed'] ?? 0);
        $converted = (int) ($state['converted'] ?? 0);
        $failed    = (int) ($state['failed'] ?? 0);

        foreach ($ids as $attachment_id) {
            $result = $converter->convert_attachment((int) $attachment_id);
            if (is_wp_error($result)) {
                FailedLog::add((int) $attachment_id, $result->get_error_message());
                $failed++;
            } elseif (!empty($result['webp']) || !empty($result['avif'])) {
                $converted++;
                do_action('fp_imgopt_attachment_converted', $attachment_id, $result);
            }
            $processed++;
        }

        $consumed  = $only_missing ? $fetch_limit : count($ids);
        $has_more  = $consumed === $fetch_limit;
        $new_offset = $offset + $consumed;

        if ($has_more) {
            $state['offset']    = $new_offset;
            $state['processed'] = $processed;
            $state['converted'] = $converted;
            $state['failed']    = $failed;
            update_option('fp_imgopt_bulk_state', $state);
            wp_schedule_single_event(time() + 60, 'fp_imgopt_bulk_cron');
        } else {
            delete_option('fp_imgopt_bulk_state');
            $this->invalidate_stats_cache();
        }
    }

    /**
     * Pulizia: se il cron è schedulato ma lo stato è stato cancellato, rimuovi il cron.
     */
    public function maybe_schedule_bulk_cron(): void {
        if (!wp_next_scheduled('fp_imgopt_bulk_cron')) {
            return;
        }
        $state = get_option('fp_imgopt_bulk_state');
        if (is_array($state) && isset($state['offset'])) {
            return;
        }
        wp_clear_scheduled_hook('fp_imgopt_bulk_cron');
    }

    public function get_settings(): Settings {
        return $this->settings;
    }
}
