<?php
/**
 * Plugin Name:       FP Image Optimizer
 * Plugin URI:        https://github.com/franpass87/FP-Image-Optimizer
 * Description:       Converte le immagini della Media Library in WebP e AVIF per ridurre peso e migliorare le performance.
 * Version:           1.7.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Francesco Passeri
 * Author URI:        https://francescopasseri.com
 * License:           Proprietary
 * Text Domain:       fp-imgopt
 * Domain Path:       /languages
 * GitHub Plugin URI: franpass87/FP-Image-Optimizer
 * Primary Branch:    main
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('FP_IMGOPT_VERSION', '1.7.4');
define('FP_IMGOPT_FILE', __FILE__);
define('FP_IMGOPT_DIR', plugin_dir_path(__FILE__));
define('FP_IMGOPT_URL', plugin_dir_url(__FILE__));

if (!file_exists(FP_IMGOPT_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>FP Image Optimizer:</strong> ';
        echo esc_html__('Esegui `composer install` nella cartella del plugin oppure carica la cartella vendor.', 'fp-imgopt');
        echo '</p></div>';
    });
    return;
}
require_once FP_IMGOPT_DIR . 'vendor/autoload.php';

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('fp-imgopt', false, dirname(plugin_basename(__FILE__)) . '/languages');
    \FP\ImgOpt\Core\Plugin::instance()->init();
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('fp_imgopt_bulk_cron');
    delete_option('fp_imgopt_bulk_state');
});
