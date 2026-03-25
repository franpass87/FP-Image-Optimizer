<?php

declare(strict_types=1);

namespace FP\ImgOpt\Frontend;

use FP\ImgOpt\Admin\Settings;

/**
 * Sostituisce i tag <img> con <picture> per servire WebP/AVIF con fallback.
 *
 * Supporta srcset responsive: se l'img ha srcset con più dimensioni,
 * genera source con srcset completo per WebP e AVIF.
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

        $post_id = get_the_ID();
        if ($post_id && $this->should_skip_replace_for_post((int) $post_id)) {
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
        if ($this->should_skip_replace_for_post($post_id)) {
            return $html;
        }
        return preg_replace_callback(
            '/<img([^>]+)src=["\']([^"\']+)["\']([^>]*)>/i',
            [$this, 'replace_single_image'],
            $html
        );
    }

    /**
     * Filtro WooCommerce: sostituisce le immagini galleria prodotto.
     *
     * @param string $html HTML dell'immagine
     * @param int $attachment_id ID allegato
     */
    public function replace_woocommerce_image(string $html, int $attachment_id): string {
        if (strpos($html, '<img') === false) {
            return $html;
        }
        $post_id = get_the_ID();
        if ($post_id && $this->should_skip_replace_for_post($post_id)) {
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
        $full   = $matches[0];

        if ((bool) apply_filters('fp_imgopt_skip_picture_replace', false, $src)) {
            return $full;
        }

        $srcset_sizes = $this->parse_srcset_sizes($before . ' ' . $after);
        $urls         = $this->collect_srcset_urls($src, $srcset_sizes['srcset']);
        $variants     = $this->get_variant_srcsets($urls);

        $variants = apply_filters('fp_imgopt_variant_urls', $variants, $src);
        if (empty($variants['avif']) && empty($variants['webp'])) {
            return $full;
        }

        $sources = '';
        if (($this->settings->get('format_avif', true)) && !empty($variants['avif'])) {
            $sizes_attr = $srcset_sizes['sizes'] !== '' ? ' sizes="' . esc_attr($srcset_sizes['sizes']) . '"' : '';
            $sources   .= sprintf(
                '<source type="image/avif" srcset="%s"%s>',
                esc_attr($variants['avif']),
                $sizes_attr
            );
        }
        if (($this->settings->get('format_webp', true)) && !empty($variants['webp'])) {
            $sizes_attr = $srcset_sizes['sizes'] !== '' ? ' sizes="' . esc_attr($srcset_sizes['sizes']) . '"' : '';
            $sources   .= sprintf(
                '<source type="image/webp" srcset="%s"%s>',
                esc_attr($variants['webp']),
                $sizes_attr
            );
        }

        if ($sources === '') {
            return $full;
        }

        $attrs_tail = $before . $after;
        $lazy       = '';
        if (!preg_match('/\sloading\s*=/i', $attrs_tail)) {
            $lazy .= ' loading="lazy"';
        }
        if (!preg_match('/\sdecoding\s*=/i', $attrs_tail)) {
            $lazy .= ' decoding="async"';
        }

        $picture = sprintf(
            '<picture>%s<img%ssrc="%s"%s%s></picture>',
            $sources,
            $before,
            esc_url($src),
            $after,
            $lazy
        );

        return (string) apply_filters('fp_imgopt_picture_html', $picture, $src, $variants);
    }

    /**
     * Verifica se saltare la sostituzione per un determinato post.
     */
    private function should_skip_replace_for_post(int $post_id): bool {
        if (get_post_meta($post_id, 'fp_imgopt_skip', true)) {
            return true;
        }
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        $excluded = $this->settings->get('exclude_replace_post_types', '');
        $types    = array_filter(array_map('trim', explode(',', (string) $excluded)));
        return in_array($post->post_type, $types, true);
    }

    /**
     * Estrae srcset e sizes dall'attributo after.
     *
     * @return array{srcset: string, sizes: string}
     */
    private function parse_srcset_sizes(string $attrs): array {
        $srcset = '';
        $sizes  = '';
        if (preg_match('/srcset\s*=\s*["\']([^"\']+)["\']/i', $attrs, $m)) {
            $srcset = trim($m[1]);
        }
        if (preg_match('/sizes\s*=\s*["\']([^"\']+)["\']/i', $attrs, $m)) {
            $sizes = trim($m[1]);
        }
        return ['srcset' => $srcset, 'sizes' => $sizes];
    }

    /**
     * Raccoglie gli URL da src e srcset con i relativi descriptor.
     *
     * @return array<int, array{url: string, descriptor: string}>
     */
    private function collect_srcset_urls(string $src, string $srcset_str): array {
        $collected = [];
        $seen_urls = [];

        if ($srcset_str !== '') {
            foreach (array_map('trim', explode(',', $srcset_str)) as $part) {
                if ($part === '') {
                    continue;
                }
                if (preg_match('/^(.+?)\s+([\d.]+[wx])$/i', $part, $m)) {
                    $url        = trim($m[1]);
                    $descriptor = $m[2];
                } else {
                    $url        = $part;
                    $descriptor = '1x';
                }
                $url_clean = strtok($url, '?');
                if ($url_clean && !isset($seen_urls[$url_clean])) {
                    $seen_urls[$url_clean] = true;
                    $collected[]          = ['url' => $url_clean, 'descriptor' => $descriptor];
                }
            }
        }

        $src_clean = strtok($src, '?') ?: $src;
        if (!isset($seen_urls[$src_clean])) {
            $collected[] = ['url' => $src_clean, 'descriptor' => '1x'];
        }

        return $collected;
    }

    /**
     * Costruisce stringhe srcset per WebP e AVIF da una lista di URL.
     *
     * @param array<int, array{url: string, descriptor: string}> $urls
     * @return array{webp?: string, avif?: string}
     */
    private function get_variant_srcsets(array $urls): array {
        $webp_parts = [];
        $avif_parts = [];

        foreach ($urls as $item) {
            $variants = $this->get_variant_urls($item['url']);
            if (!empty($variants['webp'])) {
                $webp_parts[] = esc_url($variants['webp']) . ' ' . $item['descriptor'];
            }
            if (!empty($variants['avif'])) {
                $avif_parts[] = esc_url($variants['avif']) . ' ' . $item['descriptor'];
            }
        }

        $result = [];
        if ($webp_parts !== []) {
            $result['webp'] = implode(', ', $webp_parts);
        }
        if ($avif_parts !== []) {
            $result['avif'] = implode(', ', $avif_parts);
        }

        return $result;
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
        if ($rel_path === '' || str_contains($rel_path, '..')) {
            return [];
        }
        $full_path = rtrim($base_dir, '/\\') . '/' . $rel_path;

        $base_real = realpath($base_dir);
        $path_real = realpath($full_path);
        if (!$base_real || !$path_real || !is_file($full_path)) {
            return [];
        }
        if (strpos($path_real, $base_real) !== 0) {
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
