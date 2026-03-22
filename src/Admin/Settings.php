<?php

declare(strict_types=1);

namespace FP\ImgOpt\Admin;

/**
 * Gestione impostazioni del plugin FP Image Optimizer.
 */
final class Settings {

    private const OPTION_KEY = 'fp_imgopt_settings';

    private const DEFAULTS = [
        'enabled'         => false,
        'format_webp'     => true,
        'format_avif'     => true,
        'quality_webp'    => 82,
        'quality_avif'    => 75,
        'replace_content' => true,
        'on_upload'       => true,
        'rename_files'    => false,
    ];

    private array $data;

    public function __construct() {
        $saved      = get_option(self::OPTION_KEY, []);
        $this->data = wp_parse_args(is_array($saved) ? $saved : [], self::DEFAULTS);
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public function all(): array {
        return $this->data;
    }

    /**
     * Salva le impostazioni.
     *
     * @param array<string, mixed> $data Dati da salvare (sanitizzati).
     */
    public function save(array $data): void {
        $sanitized = [
            'enabled'         => !empty($data['enabled']),
            'format_webp'     => !empty($data['format_webp']),
            'format_avif'     => !empty($data['format_avif']),
            'quality_webp'    => min(100, max(1, absint($data['quality_webp'] ?? 82))),
            'quality_avif'    => min(100, max(1, absint($data['quality_avif'] ?? 75))),
            'replace_content' => !empty($data['replace_content']),
            'on_upload'       => !empty($data['on_upload']),
            'rename_files'    => !empty($data['rename_files']),
        ];
        update_option(self::OPTION_KEY, $sanitized);
        $this->data = wp_parse_args($sanitized, self::DEFAULTS);
    }
}
