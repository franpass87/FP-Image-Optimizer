<?php

declare(strict_types=1);

namespace FP\ImgOpt\Services;

use FP\ImgOpt\Admin\Settings;
use WP_Error;

/**
 * Converte immagini JPG/PNG in WebP e AVIF.
 *
 * Usa GD o Imagick a seconda della disponibilità. AVIF richiede PHP 8.1+ con libavif (GD)
 * o Imagick con supporto AVIF.
 */
final class ImageConverter {

    private Settings $settings;

    /** @var bool|null Cache per richiesta (queryFormats Imagick è costosa). */
    private ?bool $supports_webp_cache = null;

    /** @var bool|null */
    private ?bool $supports_avif_cache = null;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * Verifica che il percorso sia sotto la cartella upload (anti path traversal; slash Windows/Unix).
     */
    private function is_path_under_upload_base(string $path, string $base_dir): bool {
        $base = trailingslashit(wp_normalize_path(realpath($base_dir) ?: $base_dir));
        $p    = wp_normalize_path(realpath($path) ?: $path);

        return $base !== '/' && str_starts_with($p, $base);
    }

    /**
     * Hook su wp_generate_attachment_metadata: genera varianti per tutte le dimensioni.
     *
     * @param array<string, mixed> $metadata
     * @param int $attachment_id
     * @return array<string, mixed>
     */
    public function on_generate_metadata(array $metadata, int $attachment_id): array {
        if (!$this->settings->get('on_upload', true)) {
            return $metadata;
        }

        $file_rel = $metadata['file'] ?? '';
        if (!is_string($file_rel) || $file_rel === '' || str_contains($file_rel, '..')) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return $metadata;
        }
        $base_dir  = trailingslashit($upload_dir['basedir']);
        $rel_dir   = dirname($file_rel) . '/';

        $files_to_convert = [];

        $main_path = $base_dir . $file_rel;
        if (is_file($main_path)) {
            $main_real = realpath($main_path);
            if ($main_real && $this->is_path_under_upload_base($main_path, $base_dir)) {
                $files_to_convert[$main_real] = $main_path;
            }
        }
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_data) {
                $sf = $size_data['file'] ?? '';
                if ($sf === '' || str_contains($sf, '..')) {
                    continue;
                }
                $sf = basename($sf);
                if ($sf === '') {
                    continue;
                }
                $size_path = $base_dir . $rel_dir . $sf;
                if (is_file($size_path)) {
                    $size_real = realpath($size_path);
                    if ($size_real && $this->is_path_under_upload_base($size_path, $base_dir) && !isset($files_to_convert[$size_real])) {
                        $files_to_convert[$size_real] = $size_path;
                    }
                }
            }
        }

        foreach ($files_to_convert as $path) {
            if (!is_file($path) || !$this->is_supported_source($path)) {
                continue;
            }
            try {
                $this->convert_file($path);
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[FP-IMGOPT] on_generate_metadata: ' . $path . ' | ' . $e->getMessage());
                }
            }
        }

        return $metadata;
    }

    /**
     * Converte un singolo attachment (usato da bulk AJAX).
     *
     * @return array{webp?: bool, avif?: bool}|WP_Error
     */
    public function convert_attachment(int $attachment_id): array|WP_Error {
        $path = get_attached_file($attachment_id);
        if (!$path || !is_file($path)) {
            return new WP_Error('fp_imgopt_not_found', __('File allegato non trovato.', 'fp-imgopt'));
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!$metadata) {
            return new WP_Error('fp_imgopt_no_metadata', __('Metadata allegato non disponibile.', 'fp-imgopt'));
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('fp_imgopt_upload_dir', $upload_dir['error']);
        }

        $base_dir  = trailingslashit($upload_dir['basedir']);
        $rel_dir   = dirname($metadata['file'] ?? '') . '/';

        $result = ['webp' => false, 'avif' => false];
        $seen   = [];

        if ($this->is_path_under_upload_base($path, $base_dir)) {
            $path_real = realpath($path);
            if ($path_real) {
                $seen[$path_real] = true;
            }
            if (is_file($path) && $this->is_supported_source($path)) {
                $r = $this->convert_file($path);
                if ($r['webp'] ?? false) {
                    $result['webp'] = true;
                }
                if ($r['avif'] ?? false) {
                    $result['avif'] = true;
                }
            }
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $s) {
                $sf = $s['file'] ?? '';
                if ($sf === '' || str_contains($sf, '..')) {
                    continue;
                }
                $sf = basename($sf);
                if ($sf === '') {
                    continue;
                }
                $file = $base_dir . $rel_dir . $sf;
                if (!is_file($file) || !$this->is_supported_source($file)) {
                    continue;
                }
                $file_real = realpath($file);
                if (!$file_real || !$this->is_path_under_upload_base($file, $base_dir) || isset($seen[$file_real])) {
                    continue;
                }
                $seen[$file_real] = true;
                $r = $this->convert_file($file);
                if ($r['webp'] ?? false) {
                    $result['webp'] = true;
                }
                if ($r['avif'] ?? false) {
                    $result['avif'] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Converte un singolo file in WebP e/o AVIF.
     *
     * @return array{webp: bool, avif: bool}
     */
    public function convert_file(string $path): array {
        $result = ['webp' => false, 'avif' => false];

        if (!is_file($path) || !$this->is_supported_source($path)) {
            return $result;
        }

        $dims = @getimagesize($path);
        if (!is_array($dims) || !isset($dims[0], $dims[1])) {
            return $result;
        }
        $w = (int) $dims[0];
        $h = (int) $dims[1];

        $min_dim = (int) $this->settings->get('skip_min_dimension', 0);
        if ($min_dim > 0 && ($w < $min_dim || $h < $min_dim)) {
            return $result;
        }

        /** @var int $max_px Filtro: 0 = nessun limite. Default riduce rischio memoria/fatal su upload enormi. */
        $max_px = (int) apply_filters('fp_imgopt_max_source_pixels', 20_000_000);
        if ($max_px > 0 && ($w * $h) > $max_px) {
            return $result;
        }

        $do_webp = $this->settings->get('format_webp', true) && $this->supports_webp();
        $do_avif = $this->settings->get('format_avif', true) && $this->supports_avif();

        if (!$do_webp && !$do_avif) {
            return $result;
        }

        $image = null;

        try {
            $image = $this->load_image($path);
            if (!$image) {
                return $result;
            }

            $base = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($path, PATHINFO_FILENAME);

            if ($do_webp) {
                $webp_path = $base . '.webp';
                if ($this->is_valid_image_file($webp_path)) {
                    $result['webp'] = true;
                } elseif ($this->save_webp($image, $webp_path)) {
                    if ($this->is_valid_image_file($webp_path)) {
                        $result['webp'] = true;
                    } else {
                        @unlink($webp_path);
                    }
                }
            }

            // Imagick: dopo WEBP lo stato interno può corrompersi; ricarica da disco prima di AVIF (evita crash/fatal).
            if ($do_avif) {
                if ($image instanceof \Imagick && $do_webp) {
                    $this->free_image($image);
                    $image = $this->load_image($path);
                    if (!$image) {
                        return $result;
                    }
                }

                $avif_path = $base . '.avif';
                if ($this->is_valid_image_file($avif_path)) {
                    $result['avif'] = true;
                } elseif ($this->save_avif($image, $avif_path)) {
                    if ($this->is_valid_image_file($avif_path)) {
                        $result['avif'] = true;
                    } else {
                        @unlink($avif_path);
                    }
                }
            }
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[FP-IMGOPT] convert_file: ' . $path . ' | ' . $e->getMessage());
            }
        } finally {
            $this->free_image($image);
        }

        return $result;
    }

    private function is_supported_source(string $path): bool {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);
    }

    private function is_valid_image_file(string $path): bool {
        return is_file($path) && filesize($path) > 100;
    }

    public function supports_webp(): bool {
        if ($this->supports_webp_cache !== null) {
            return $this->supports_webp_cache;
        }
        if (extension_loaded('imagick')) {
            $formats = \Imagick::queryFormats();
            $this->supports_webp_cache = in_array('WEBP', $formats, true);

            return $this->supports_webp_cache;
        }
        $this->supports_webp_cache = function_exists('imagewebp');

        return $this->supports_webp_cache;
    }

    public function supports_avif(): bool {
        if ($this->supports_avif_cache !== null) {
            return $this->supports_avif_cache;
        }
        if (extension_loaded('imagick')) {
            $formats = \Imagick::queryFormats();
            $this->supports_avif_cache = in_array('AVIF', $formats, true);

            return $this->supports_avif_cache;
        }
        $this->supports_avif_cache = function_exists('imageavif');

        return $this->supports_avif_cache;
    }

    /**
     * @return \GdImage|\Imagick|null
     */
    private function load_image(string $path): \GdImage|\Imagick|null {
        if (extension_loaded('imagick')) {
            try {
                $img = new \Imagick($path);
                return $img;
            } catch (\Throwable) {
                // Fallback to GD
            }
        }

        $mime = wp_check_filetype($path, null)['type'] ?? '';
        $gd   = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png'               => @imagecreatefrompng($path),
            'image/gif'               => @imagecreatefromgif($path),
            default                   => null,
        };

        if ($gd && $mime === 'image/png') {
            imagealphablending($gd, true);
            imagesavealpha($gd, true);
        }

        return $gd;
    }

    /**
     * @param \GdImage|\Imagick $image
     */
    private function save_webp(mixed $image, string $path): bool {
        $quality = $this->settings->get('quality_webp', 82);

        if ($image instanceof \Imagick) {
            try {
                $image->setImageFormat('WEBP');
                $image->setImageCompressionQuality($quality);
                return $image->writeImage($path);
            } catch (\Throwable) {
                return false;
            }
        }

        if ($image instanceof \GdImage) {
            return (bool) imagewebp($image, $path, $quality);
        }

        return false;
    }

    /**
     * @param \GdImage|\Imagick $image
     */
    private function save_avif(mixed $image, string $path): bool {
        $quality = $this->settings->get('quality_avif', 75);

        if ($image instanceof \Imagick) {
            try {
                $image->setImageFormat('AVIF');
                $image->setImageCompressionQuality($quality);
                return $image->writeImage($path);
            } catch (\Throwable) {
                return false;
            }
        }

        if ($image instanceof \GdImage && function_exists('imageavif')) {
            return (bool) imageavif($image, $path, $quality, 6);
        }

        return false;
    }

    /**
     * @param \GdImage|\Imagick $image
     */
    private function free_image(mixed $image): void {
        if ($image === null) {
            return;
        }
        if ($image instanceof \GdImage) {
            imagedestroy($image);
        }
        if ($image instanceof \Imagick) {
            try {
                $image->clear();
                $image->destroy();
            } catch (\Throwable) {
                // Evita fatal in shutdown se Imagick è già in stato inconsistente.
            }
        }
    }
}
