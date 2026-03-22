<?php

declare(strict_types=1);

namespace FP\ImgOpt\Services;

use FP\ImgOpt\Admin\Settings;

/**
 * Crea duplicati delle immagini al salvataggio di post/pagina.
 *
 * La seconda rinominazione: quando salvi un articolo o una pagina, crea una copia
 * di ogni immagine usata nel contenuto con nome contestuale (sito-slug-id).
 * Aggiorna il contenuto per puntare ai duplicati.
 */
final class ImageDuplicatorOnSave {

    private const MAX_SLUG_LENGTH   = 50;
    private const MAX_SITENAME_LEN  = 30;

    private Settings $settings;

    private static bool $running = false;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function on_save_post(int $post_id): void {
        if (self::$running) {
            return;
        }
        $do_duplicate = $this->settings->get('duplicate_on_save', false);
        $do_seo       = $this->settings->get('seo_attributes', false);
        if (!$do_duplicate && !$do_seo) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $allowed = array_diff(['post', 'page'], $this->get_excluded_duplicate_types());
        if (!in_array($post->post_type, $allowed, true)) {
            return;
        }

        if (get_post_meta($post_id, 'fp_imgopt_skip', true)) {
            return;
        }

        $content = $post->post_content ?? '';
        if ($content === '') {
            return;
        }

        $attachment_ids = $this->extract_attachment_ids($content);
        if (empty($attachment_ids)) {
            return;
        }

        $post_title = $post->post_title ?? '';
        $post_slug  = $post->post_name ?? 'media';

        if ($do_seo) {
            foreach (array_unique($attachment_ids) as $att_id) {
                $att_id = (int) $att_id;
                if ($att_id > 0) {
                    ImageSeoHelper::ensure_seo_attributes($att_id, $post_title, $post_slug);
                }
            }
        }

        if (!$do_duplicate) {
            return;
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return;
        }

        $base_dir  = trailingslashit($upload_dir['basedir']);
        $base_url  = trailingslashit($upload_dir['baseurl']);
        $base_real = realpath($base_dir) ?: $base_dir;
        $slug      = $this->sanitize_slug($post->post_name ?? 'media');
        $site      = $this->sanitize_site();
        $converter = new ImageConverter($this->settings);

        $replacements = [];

        foreach (array_unique($attachment_ids) as $att_id) {
            $att_id = (int) $att_id;
            if ($att_id <= 0) {
                continue;
            }
            $paths = $this->get_attachment_paths($att_id, $base_dir);
            if (empty($paths)) {
                continue;
            }

            $rel_dir = trim(str_replace($base_dir, '', dirname($paths['main'])), '/');
            if (str_contains($rel_dir, '..')) {
                continue;
            }
            $full_dir  = $base_dir . $rel_dir . '/';
            $full_real = realpath($full_dir) ?: $full_dir;
            if (!$full_real || strpos($full_real, $base_real) !== 0) {
                continue;
            }
            $base_name = $site . '-' . $slug . '-' . $att_id;

            foreach ($paths as $size_key => $old_path) {
                if (!is_file($old_path) || !$this->is_copyable($old_path)) {
                    continue;
                }
                $old_file = basename($old_path);
                $ext      = strtolower(pathinfo($old_file, PATHINFO_EXTENSION));
                $new_file = $size_key === 'main'
                    ? $base_name . '.' . $ext
                    : $base_name . '-' . $size_key . '.' . $ext;
                $new_file = wp_unique_filename($full_dir, $new_file);
                $new_path = $full_dir . $new_file;

                if (copy($old_path, $new_path) && filesize($new_path) > 0) {
                    $old_rel = $rel_dir ? $rel_dir . '/' . $old_file : $old_file;
                    $new_rel = $rel_dir ? $rel_dir . '/' . $new_file : $new_file;
                    $replacements[$base_url . $old_rel] = $base_url . $new_rel;
                    $ext = strtolower(pathinfo($old_file, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                        $converter->convert_file($new_path);
                    }
                } elseif (is_file($new_path)) {
                    @unlink($new_path);
                }
            }
        }

        if (empty($replacements)) {
            return;
        }

        $new_content = $content;
        foreach ($replacements as $old_url => $new_url) {
            $new_content = str_replace($old_url, $new_url, $new_content);
        }

        if ($new_content !== $content) {
            self::$running = true;
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $new_content,
            ]);
            self::$running = false;
        }
    }

    /**
     * @return int[]
     */
    private function extract_attachment_ids(string $content): array {
        $ids = [];

        if (preg_match_all('/wp:image\s+\{"[^"]*"id":\s*(\d+)/', $content, $m)) {
            foreach ($m[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        if (preg_match_all('/wp:gallery\s+\{"[^"]*"ids":\s*\[([^\]]+)\]/', $content, $m)) {
            foreach ($m[1] as $list) {
                foreach (array_map('intval', preg_split('/\s*,\s*/', $list)) as $id) {
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        }

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $m)) {
            foreach ($m[1] as $url) {
                $id = wp_attachment_url_to_postid($url);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{main: string, string: string}
     */
    private function get_attachment_paths(int $attachment_id, string $base_dir): array {
        $path = get_attached_file($attachment_id);
        if (!$path || !is_file($path)) {
            return [];
        }

        $base_dir = trailingslashit($base_dir);
        if (strpos($path, $base_dir) !== 0) {
            return [];
        }
        $base_real = realpath($base_dir) ?: $base_dir;
        $path_real = realpath($path) ?: $path;
        if (!$path_real || strpos($path_real, $base_real) !== 0) {
            return [];
        }

        $result = ['main' => $path];

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $dir = dirname($path) . '/';
            foreach ($meta['sizes'] as $key => $size) {
                $f = $size['file'] ?? '';
                if ($f === '' || str_contains($f, '..')) {
                    continue;
                }
                $f = basename($f);
                if ($f !== '' && is_file($dir . $f)) {
                    $result[$key] = $dir . $f;
                }
            }
        }

        return $result;
    }

    private function sanitize_site(): string {
        $s = sanitize_title(get_bloginfo('name'));
        return substr($s, 0, self::MAX_SITENAME_LEN) ?: 'sito';
    }

    private function sanitize_slug(string $slug): string {
        $s = sanitize_title($slug);
        return substr($s, 0, self::MAX_SLUG_LENGTH) ?: 'media';
    }

    /**
     * @return string[]
     */
    private function get_excluded_duplicate_types(): array {
        $raw = $this->settings->get('exclude_duplicate_post_types', '');
        $arr = array_map('trim', explode(',', (string) $raw));
        return array_filter($arr);
    }

    private function is_copyable(string $path): bool {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true);
    }
}
