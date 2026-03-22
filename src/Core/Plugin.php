<?php

declare(strict_types=1);

namespace FP\ImgOpt\Core;

use FP\ImgOpt\Admin\Settings;
use FP\ImgOpt\Admin\SettingsPage;
use FP\ImgOpt\Frontend\PictureReplacer;
use FP\ImgOpt\Services\ImageConverter;

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
        add_filter('media_row_actions', [$this, 'add_media_row_action'], 10, 2);

        if (!$this->settings->get('enabled', false)) {
            return;
        }

        $converter = new ImageConverter($this->settings);
        add_filter('wp_generate_attachment_metadata', [$converter, 'on_generate_metadata'], 10, 2);

        if ($this->settings->get('replace_content', true)) {
            $replacer = new PictureReplacer($this->settings);
            add_filter('the_content', [$replacer, 'replace_images'], 20);
            add_filter('post_thumbnail_html', [$replacer, 'replace_thumbnail'], 10, 5);
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
        add_options_page(
            __('FP Image Optimizer', 'fp-imgopt'),
            __('FP Image Optimizer', 'fp-imgopt'),
            'manage_options',
            'fp-imgopt',
            [new SettingsPage($this->settings), 'render']
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

        wp_localize_script('fp-imgopt-admin', 'fpImgOptConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('fp_imgopt_admin'),
            'i18n'    => [
                'converting' => __('Conversione in corso...', 'fp-imgopt'),
                'done'       => __('Completato.', 'fp-imgopt'),
                'error'      => __('Errore durante la conversione.', 'fp-imgopt'),
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

        $converter = new ImageConverter($this->settings);
        $result    = $converter->convert_attachment($attachment_id);

        if (is_wp_error($result)) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }
            wp_die(esc_html($result->get_error_message()));
        }

        if (wp_doing_ajax()) {
            wp_send_json_success($result);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('upload.php'));
        exit;
    }

    public function get_settings(): Settings {
        return $this->settings;
    }
}
