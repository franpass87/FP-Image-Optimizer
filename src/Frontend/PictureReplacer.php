<?php

declare(strict_types=1);

namespace FP\ImgOpt\Frontend;

use FP\ImgOpt\Admin\Settings;

/**
 * Sostituisce i tag <img> con <picture> per servire WebP/AVIF con fallback.
 */
final class PictureReplacer {

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * Filtro the_content: sostituisce img con picture per immagini della Media Library.
     */
    public function replace_images(string $content): string {
        if (strpos($content, '<img') === false) {
            return $content;
        }

        return preg_replace_callback(
            '/<img([^>]+)src=["\']([^"\']+)["\']([^>]*)>/i',
            [$this, 'replace_single_image'],
            $content
        );
    }

    /**
     * Filtro post_thumbnail_html.
     *
     * @param string $html
     * @param int $post_id
     * @param int $post_thumbnail_id
     * @param string|int[] $size
     * @param array $attr
     */
    public function replace_thumbnail(
        string $html,
        int $post_id,
        int $post_thumbnail_id,
        $size,
        array $attr
    ): string {
        if (strpos($html, '<img') === false) {
            return $html;
        }
        return preg_replace_callback(
            '/<img([^>]+)src=["\']([^"\']+)["\']([^>]*)>/i',
            [$this, 'replace_single_image'],
            $html
        );
    }

    /**
     * @param array<int, string> $matches
     */
    private function replace_single_image(array $matches): string {
        $before = $matches[1];
        $src    = $matches[2];
        $after  = $matches[3];

        $variants = $this->get_variant_urls($src);
        if (empty($variants)) {
            return $matches[0];
        }

        $sources = '';
        if (($this->settings->get('format_avif', true)) && !empty($variants['avif'])) {
            $sources .= sprintf(
                '<source type="image/avif" srcset="%s">',
                esc_url($variants['avif'])
            );
        }
        if (($this->settings->get('format_webp', true)) && !empty($variants['webp'])) {
            $sources .= sprintf(
                '<source type="image/webp" srcset="%s">',
                esc_url($variants['webp'])
            );
        }

        if ($sources === '') {
            return $matches[0];
        }

        return sprintf(
            '<picture>%s<img%ssrc="%s"%s loading="lazy" decoding="async"></picture>',
            $sources,
            $before,
            esc_url($src),
            $after
        );
    }

    /**
     * Restituisce gli URL delle varianti WebP/AVIF se esistono.
     *
     * @return array{webp?: string, avif?: string}
     */
    private function get_variant_urls(string $url): array {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return [];
        }

        $base_url = $upload_dir['baseurl'];
        $base_dir = $upload_dir['basedir'];

        $url_clean = strtok($url, '?');
        if (!$url_clean || strpos($url_clean, $base_url) !== 0) {
            return [];
        }

        $rel_path  = substr($url_clean, strlen($base_url));
        $rel_path  = ltrim(str_replace('\\', '/', $rel_path), '/');
        $full_path = $base_dir . '/' . $rel_path;

        if (!is_file($full_path)) {
            return [];
        }

        $path_info = pathinfo($full_path);
        $dir       = $path_info['dirname'] . '/';
        $filename  = $path_info['filename'];
        $result    = [];

        $base_dir_real = trailingslashit(realpath($base_dir) ?: $base_dir);
        $dir_real      = realpath($dir) ?: $dir;
        $rel_from_base = str_replace($base_dir_real, '', trailingslashit($dir_real));
        $rel_from_base = ltrim(str_replace('\\', '/', $rel_from_base), '/');
        $base_url_t    = trailingslashit($base_url);

        $webp_path = $dir . $filename . '.webp';
        if ($this->settings->get('format_webp', true) && is_file($webp_path) && filesize($webp_path) > 100) {
            $result['webp'] = $base_url_t . $rel_from_base . $filename . '.webp';
        }

        $avif_path = $dir . $filename . '.avif';
        if ($this->settings->get('format_avif', true) && is_file($avif_path) && filesize($avif_path) > 100) {
            $result['avif'] = $base_url_t . $rel_from_base . $filename . '.avif';
        }

        return $result;
    }
}
