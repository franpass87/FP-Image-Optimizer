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

    private const SUPPORTED_SOURCE = ['image/jpeg', 'image/png', 'image/gif'];

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
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

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return $metadata;
        }
        $base_dir = trailingslashit($upload_dir['basedir']);
        $rel_dir  = dirname($metadata['file'] ?? '') . '/';

        $files_to_convert = [];

        if (!empty($metadata['file'])) {
            $files_to_convert[] = $base_dir . $metadata['file'];
        }
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_data) {
                if (!empty($size_data['file'])) {
                    $files_to_convert[] = $base_dir . $rel_dir . $size_data['file'];
                }
            }
        }

        foreach ($files_to_convert as $path) {
            if (is_file($path) && $this->is_supported_source($path)) {
                $this->convert_file($path);
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

        $base_dir = trailingslashit($upload_dir['basedir']);
        $rel_dir  = dirname($metadata['file'] ?? '') . '/';

        $result = ['webp' => false, 'avif' => false];

        $files = [$path];
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $s) {
                if (!empty($s['file'])) {
                    $files[] = $base_dir . $rel_dir . $s['file'];
                }
            }
        }

        foreach ($files as $file) {
            if (!is_file($file) || !$this->is_supported_source($file)) {
                continue;
            }
            $r = $this->convert_file($file);
            if ($r['webp'] ?? false) {
                $result['webp'] = true;
            }
            if ($r['avif'] ?? false) {
                $result['avif'] = true;
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

        $do_webp = $this->settings->get('format_webp', true) && $this->supports_webp();
        $do_avif = $this->settings->get('format_avif', true) && $this->supports_avif();

        if (!$do_webp && !$do_avif) {
            return $result;
        }

        $image = $this->load_image($path);
        if (!$image) {
            return $result;
        }

        $base = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($path, PATHINFO_FILENAME);

        if ($do_webp) {
            $webp_path = $base . '.webp';
            if ($this->save_webp($image, $webp_path)) {
                $result['webp'] = true;
            }
        }

        if ($do_avif) {
            $avif_path = $base . '.avif';
            if ($this->save_avif($image, $avif_path)) {
                $result['avif'] = true;
            }
        }

        $this->free_image($image);

        return $result;
    }

    private function is_supported_source(string $path): bool {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);
    }

    public function supports_webp(): bool {
        if (extension_loaded('imagick')) {
            $formats = \Imagick::queryFormats();
            return in_array('WEBP', $formats, true);
        }
        return function_exists('imagewebp');
    }

    public function supports_avif(): bool {
        if (extension_loaded('imagick')) {
            $formats = \Imagick::queryFormats();
            return in_array('AVIF', $formats, true);
        }
        return function_exists('imageavif');
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
        if ($image instanceof \GdImage) {
            imagedestroy($image);
        }
        if ($image instanceof \Imagick) {
            $image->clear();
            $image->destroy();
        }
    }
}
