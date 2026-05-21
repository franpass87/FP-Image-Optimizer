<?php
/**
 * Runtime diagnostics FP Image Optimizer (read-only, Bridge-safe).
 *
 * @package FP\ImgOpt\Services\Diagnostics
 */

declare(strict_types=1);

namespace FP\ImgOpt\Services\Diagnostics;

use FP\ImgOpt\Admin\Settings;
use FP\ImgOpt\Core\Plugin;
use FP\ImgOpt\Services\FailedLog;
use FP\ImgOpt\Services\ImageConverter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Snapshot operativo per FP Remote Bridge (`imgopt_runtime`).
 */
final class RuntimeDiagnostics
{
    public const SECTION_OPTIMIZATION_CONTEXT = 'optimization_context';
    public const SECTION_CONVERSION_SUMMARY = 'conversion_summary';
    public const SECTION_BULK_PIPELINE = 'bulk_pipeline';
    public const SECTION_SETTINGS = 'settings';
    public const SECTION_INTEGRATIONS = 'integrations';
    public const SECTION_ADMIN_HEALTH = 'admin_health';
    public const SECTION_CRON = 'cron';
    public const SECTION_PROBLEMS = 'problems';

    public const BULK_CRON_HOOK = 'fp_imgopt_bulk_cron';

    public const ALL_SECTIONS = [
        self::SECTION_OPTIMIZATION_CONTEXT,
        self::SECTION_CONVERSION_SUMMARY,
        self::SECTION_BULK_PIPELINE,
        self::SECTION_SETTINGS,
        self::SECTION_INTEGRATIONS,
        self::SECTION_ADMIN_HEALTH,
        self::SECTION_CRON,
        self::SECTION_PROBLEMS,
    ];

    /**
     * @param array<int, string>   $sections
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function build(array $sections = [], array $options = []): array
    {
        $requested = $sections === [] ? self::ALL_SECTIONS : array_values(array_intersect($sections, self::ALL_SECTIONS));
        if ($requested === []) {
            $requested = self::ALL_SECTIONS;
        }

        $missingSampleLimit = isset($options['missing_sample_limit']) ? max(1, min(100, (int) $options['missing_sample_limit'])) : 50;

        $payload = [
            'plugin_active' => true,
            'plugin_version' => defined('FP_IMGOPT_VERSION') ? (string) FP_IMGOPT_VERSION : '',
            'available_sections' => self::ALL_SECTIONS,
            'requested_sections' => $requested,
            'generated_at_gmt' => gmdate('Y-m-d H:i:s'),
        ];

        foreach ($requested as $section) {
            switch ($section) {
                case self::SECTION_OPTIMIZATION_CONTEXT:
                    $payload['optimization_context'] = self::build_optimization_context();
                    break;
                case self::SECTION_CONVERSION_SUMMARY:
                    $payload['conversion_summary'] = self::build_conversion_summary($missingSampleLimit);
                    break;
                case self::SECTION_BULK_PIPELINE:
                    $payload['bulk_pipeline'] = self::build_bulk_pipeline();
                    break;
                case self::SECTION_SETTINGS:
                    $payload['settings'] = self::build_settings();
                    break;
                case self::SECTION_INTEGRATIONS:
                    $payload['integrations'] = self::build_integrations();
                    break;
                case self::SECTION_ADMIN_HEALTH:
                    $payload['admin_health'] = self::build_admin_health();
                    break;
                case self::SECTION_CRON:
                    $payload['cron'] = self::build_cron();
                    break;
                case self::SECTION_PROBLEMS:
                    $payload['problems'] = self::collect_problems($payload);
                    break;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_optimization_context(): array
    {
        $settings = new Settings();
        $converter = new ImageConverter($settings);

        return [
            'enabled' => (bool) $settings->get('enabled', false),
            'on_upload' => (bool) $settings->get('on_upload', true),
            'replace_content' => (bool) $settings->get('replace_content', true),
            'rename_files' => (bool) $settings->get('rename_files', false),
            'duplicate_on_save' => (bool) $settings->get('duplicate_on_save', false),
            'seo_attributes' => (bool) $settings->get('seo_attributes', false),
            'format_webp_requested' => (bool) $settings->get('format_webp', true),
            'format_avif_requested' => (bool) $settings->get('format_avif', true),
            'supports_webp' => $converter->supports_webp(),
            'supports_avif' => $converter->supports_avif(),
            'bulk_batch_size' => max(1, min(50, (int) apply_filters('fp_imgopt_bulk_batch_size', 5))),
            'max_source_pixels' => (int) apply_filters('fp_imgopt_max_source_pixels', 20_000_000),
            'skip_min_dimension' => (int) $settings->get('skip_min_dimension', 0),
            'supported_mimes' => ['image/jpeg', 'image/png', 'image/gif'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_conversion_summary(int $missingSampleLimit): array
    {
        $cached = get_transient('fp_imgopt_stats');
        $stats = is_array($cached) ? $cached : Plugin::instance()->compute_stats();
        $failed = FailedLog::get();

        $plugin = Plugin::instance();
        $missingSample = 0;
        $sampled = 0;
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => $missingSampleLimit,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        foreach ($ids as $id) {
            ++$sampled;
            if (!$plugin->attachment_has_variants((int) $id)) {
                ++$missingSample;
            }
        }

        $failuresRecent = [];
        foreach (array_slice($failed, 0, 10) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $failuresRecent[] = [
                'attachment_id' => (int) ($entry['attachment_id'] ?? 0),
                'message_truncated' => mb_substr((string) ($entry['message'] ?? ''), 0, 120),
                'timestamp_gmt' => isset($entry['timestamp']) ? gmdate('Y-m-d H:i:s', (int) $entry['timestamp']) : '',
            ];
        }

        return [
            'total_images' => (int) ($stats['total_images'] ?? 0),
            'with_variants' => (int) ($stats['with_variants'] ?? 0),
            'webp_count' => (int) ($stats['webp_count'] ?? 0),
            'avif_count' => (int) ($stats['avif_count'] ?? 0),
            'saved_mb' => (float) ($stats['saved_mb'] ?? 0),
            'stats_capped' => !empty($stats['capped']),
            'failed_log_count' => count($failed),
            'failures_recent' => $failuresRecent,
            'missing_variants_sample' => [
                'sampled' => $sampled,
                'missing' => $missingSample,
                'limit' => $missingSampleLimit,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_bulk_pipeline(): array
    {
        $state = get_option('fp_imgopt_bulk_state', []);
        $state = is_array($state) ? $state : [];
        $active = $state !== [] && isset($state['offset']);
        $next = wp_next_scheduled(self::BULK_CRON_HOOK);

        return [
            'bulk_active' => $active,
            'bulk_state' => $active ? [
                'offset' => (int) ($state['offset'] ?? 0),
                'processed' => (int) ($state['processed'] ?? 0),
                'converted' => (int) ($state['converted'] ?? 0),
                'failed' => (int) ($state['failed'] ?? 0),
                'only_missing' => !empty($state['only_missing']),
            ] : null,
            'cron_next_run_gmt' => $next ? gmdate('Y-m-d H:i:s', (int) $next) : null,
            'cron_is_overdue' => $next !== false && (int) $next < time(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_settings(): array
    {
        $settings = new Settings();
        $all = $settings->all();

        return [
            'quality_webp' => (int) ($all['quality_webp'] ?? 82),
            'quality_avif' => (int) ($all['quality_avif'] ?? 75),
            'exclude_duplicate_post_types_chars' => strlen((string) ($all['exclude_duplicate_post_types'] ?? '')),
            'exclude_replace_post_types_chars' => strlen((string) ($all['exclude_replace_post_types'] ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_integrations(): array
    {
        $upload = wp_upload_dir();
        $imagick = extension_loaded('imagick');
        $webpImagick = false;
        $avifImagick = false;
        if ($imagick && class_exists('Imagick')) {
            $formats = \Imagick::queryFormats();
            $webpImagick = in_array('WEBP', $formats, true);
            $avifImagick = in_array('AVIF', $formats, true);
        }

        return [
            'php_version' => PHP_VERSION,
            'gd_loaded' => extension_loaded('gd'),
            'imagick_loaded' => $imagick,
            'imagick_webp' => $webpImagick,
            'imagick_avif' => $avifImagick,
            'woocommerce_active' => class_exists('WooCommerce'),
            'woocommerce_version' => defined('WC_VERSION') ? (string) WC_VERSION : null,
            'upload_dir_error' => !empty($upload['error']) ? (string) $upload['error'] : '',
            'upload_dir_writable' => empty($upload['error']) && is_writable($upload['basedir'] ?? ''),
            'filters_registered' => [
                'fp_imgopt_bulk_batch_size' => has_filter('fp_imgopt_bulk_batch_size'),
                'fp_imgopt_max_source_pixels' => has_filter('fp_imgopt_max_source_pixels'),
                'fp_imgopt_skip_picture_replace' => has_filter('fp_imgopt_skip_picture_replace'),
                'fp_imgopt_variant_urls' => has_filter('fp_imgopt_variant_urls'),
                'fp_imgopt_picture_html' => has_filter('fp_imgopt_picture_html'),
            ],
            'hooks_when_enabled' => [
                'the_content' => has_filter('the_content'),
                'post_thumbnail_html' => has_filter('post_thumbnail_html'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_admin_health(): array
    {
        return [
            'rest_api' => false,
            'ajax_actions' => [
                'fp_imgopt_convert',
                'fp_imgopt_bulk_convert',
                'fp_imgopt_stats',
                'fp_imgopt_remove_variants',
                'fp_imgopt_clear_log',
                'fp_imgopt_bulk_start_background',
                'fp_imgopt_bulk_state',
                'fp_imgopt_retry_failed',
                'fp_imgopt_rename_post_images',
            ],
            'permission' => 'manage_options',
            'nonces' => ['fp_imgopt_admin', 'fp_imgopt_convert_{id}', 'fp_imgopt_save_settings', 'fp_imgopt_skip_meta'],
            'menu_slug' => 'fp-imgopt',
            'submenu_slug' => 'fp-imgopt-rename-by-post',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_cron(): array
    {
        $next = wp_next_scheduled(self::BULK_CRON_HOOK);

        return [
            'scheduled_hooks' => [
                [
                    'hook' => self::BULK_CRON_HOOK,
                    'schedule' => 'single',
                    'next_run_gmt' => $next ? gmdate('Y-m-d H:i:s', (int) $next) : null,
                    'is_overdue' => $next !== false && (int) $next < time(),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, string>>
     */
    private static function collect_problems(array $payload): array
    {
        $problems = [];

        $ctx = isset($payload['optimization_context']) && is_array($payload['optimization_context']) ? $payload['optimization_context'] : [];
        if (empty($ctx['enabled'])) {
            $problems[] = [
                'code' => 'optimization_disabled',
                'severity' => 'medium',
                'message' => 'Ottimizzazione disabilitata nelle impostazioni.',
            ];
        }
        if (!empty($ctx['format_webp_requested']) && empty($ctx['supports_webp'])) {
            $problems[] = [
                'code' => 'webp_unavailable',
                'severity' => 'high',
                'message' => 'WebP richiesto ma GD/Imagick non supporta la conversione.',
            ];
        }
        if (!empty($ctx['format_avif_requested']) && empty($ctx['supports_avif'])) {
            $problems[] = [
                'code' => 'avif_unavailable',
                'severity' => 'medium',
                'message' => 'AVIF richiesto ma non disponibile su questo PHP/Imagick.',
            ];
        }

        $bulk = isset($payload['bulk_pipeline']) && is_array($payload['bulk_pipeline']) ? $payload['bulk_pipeline'] : [];
        if (!empty($bulk['bulk_active']) && empty($bulk['cron_next_run_gmt'])) {
            $problems[] = [
                'code' => 'bulk_stuck',
                'severity' => 'high',
                'message' => 'Bulk in corso ma cron fp_imgopt_bulk_cron non schedulato.',
            ];
        }
        if (empty($bulk['bulk_active']) && !empty($bulk['cron_next_run_gmt'])) {
            $problems[] = [
                'code' => 'bulk_cron_orphan',
                'severity' => 'medium',
                'message' => 'Cron bulk schedulato senza stato fp_imgopt_bulk_state.',
            ];
        }

        $summary = isset($payload['conversion_summary']) && is_array($payload['conversion_summary']) ? $payload['conversion_summary'] : [];
        if ((int) ($summary['failed_log_count'] ?? 0) >= 10) {
            $problems[] = [
                'code' => 'failed_log_backlog',
                'severity' => 'medium',
                'message' => 'Log errori conversione con 10+ voci recenti.',
            ];
        }
        if (!empty($summary['stats_capped'])) {
            $problems[] = [
                'code' => 'stats_capped',
                'severity' => 'low',
                'message' => 'Statistiche calcolate su campione max 2000 attachment (capped).',
            ];
        }

        $integrations = isset($payload['integrations']) && is_array($payload['integrations']) ? $payload['integrations'] : [];
        if (!empty($integrations['upload_dir_error'])) {
            $problems[] = [
                'code' => 'upload_dir_error',
                'severity' => 'high',
                'message' => 'Cartella uploads non disponibile: ' . mb_substr((string) $integrations['upload_dir_error'], 0, 80),
            ];
        } elseif (empty($integrations['upload_dir_writable'])) {
            $problems[] = [
                'code' => 'upload_dir_not_writable',
                'severity' => 'high',
                'message' => 'Cartella uploads non scrivibile.',
            ];
        }

        return $problems;
    }
}
