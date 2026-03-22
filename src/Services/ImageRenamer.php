<?php

declare(strict_types=1);

namespace FP\ImgOpt\Services;

use FP\ImgOpt\Admin\Settings;

/**
 * Rinomina i file immagine secondo il pattern: nome-sito + slug-pagina + id.
 *
 * Simile ai plugin renamer (es. Media File Renamer): migliora SEO e organizzazione.
 * Formato: {@code sitename-slug-123.ext} (es. mio-sito-contatti-456.jpg).
 */
final class ImageRenamer {

    private const MAX_SLUG_LENGTH = 50;
    private const MAX_SITENAME_LENGTH = 30;

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * Hook su wp_generate_attachment_metadata: rinomina file e aggiorna riferimenti.
     *
     * @param array<string, mixed> $metadata
     * @param int $attachment_id
     * @return array<string, mixed>
     */
    public function on_generate_metadata(array $metadata, int $attachment_id): array {
        if ($this->settings->get('seo_attributes', false)) {
            $slug  = $this->get_context_slug($attachment_id);
            $title = $this->get_context_title($attachment_id);
            ImageSeoHelper::ensure_seo_attributes($attachment_id, $title, $slug);
        }

        if (!$this->settings->get('rename_files', false)) {
            return $metadata;
        }

        $created = get_post_time('U', true, $attachment_id);
        if ($created && (time() - $created) > 120) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return $metadata;
        }

        $base_dir  = trailingslashit($upload_dir['basedir']);
        $base_url  = trailingslashit($upload_dir['baseurl']);
        $rel_dir   = dirname($metadata['file'] ?? '');
        $full_dir  = $base_dir . $rel_dir . '/';

        $main_file = $metadata['file'] ?? '';
        if (empty($main_file)) {
            return $metadata;
        }

        $main_path = $base_dir . $main_file;
        if (!is_file($main_path) || !$this->is_renamable($main_path)) {
            return $metadata;
        }

        $current_basename = pathinfo($main_file, PATHINFO_FILENAME);
        if ($this->already_renamed($current_basename, $attachment_id)) {
            return $metadata;
        }

        $base_name = $this->build_base_name($attachment_id);
        $ext       = strtolower(pathinfo($main_file, PATHINFO_EXTENSION));
        $new_name  = $base_name . '.' . $ext;
        $new_name  = wp_unique_filename($full_dir, $new_name);

        $old_url = $base_url . $main_file;
        $new_rel = $rel_dir ? $rel_dir . '/' . $new_name : $new_name;
        $new_path = $base_dir . $new_rel;

        if ($new_path === $main_path) {
            return $metadata;
        }

        if (!rename($main_path, $new_path)) {
            return $metadata;
        }

        $old_urls = [$base_url . $main_file];
        $new_urls = [$base_url . $new_rel];

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $key => $size_data) {
                $old_file = $size_data['file'] ?? '';
                if (empty($old_file)) {
                    continue;
                }
                $old_full = $base_dir . $rel_dir . '/' . $old_file;
                $size_ext = strtolower(pathinfo($old_file, PATHINFO_EXTENSION));
                $size_new = $base_name . '-' . $key . '.' . $size_ext;
                $size_new = wp_unique_filename($full_dir, $size_new);
                $size_path_new = $full_dir . $size_new;

                if (is_file($old_full) && rename($old_full, $size_path_new)) {
                    $metadata['sizes'][$key]['file'] = $size_new;
                    $old_rel = $rel_dir ? $rel_dir . '/' . $old_file : $old_file;
                    $new_rel_size = $rel_dir ? $rel_dir . '/' . $size_new : $size_new;
                    $old_urls[] = $base_url . $old_rel;
                    $new_urls[] = $base_url . $new_rel_size;
                } else {
                    rename($new_path, $main_path);
                    return $metadata;
                }
            }
        }

        $metadata['file'] = $new_rel;
        update_attached_file($attachment_id, $new_path);

        $this->update_content_references($old_urls, $new_urls, $attachment_id, $base_url . $main_file, $base_url . $new_rel);

        return $metadata;
    }

    private function build_base_name(int $attachment_id): string {
        $site = sanitize_title(get_bloginfo('name'));
        $site = substr($site, 0, self::MAX_SITENAME_LENGTH) ?: 'sito';

        $slug = $this->get_context_slug($attachment_id);
        $slug = substr($slug, 0, self::MAX_SLUG_LENGTH) ?: 'media';

        return $site . '-' . $slug . '-' . $attachment_id;
    }

    private function get_context_slug(int $attachment_id): string {
        $parent = (int) get_post_field('post_parent', $attachment_id);
        if ($parent > 0) {
            $post = get_post($parent);
            if ($post && in_array($post->post_type, ['post', 'page'], true)) {
                $name = $post->post_name ?? '';
                if ($name !== '') {
                    return sanitize_title($name);
                }
            }
        }

        if (isset($_REQUEST['post_id']) && absint($_REQUEST['post_id']) > 0) {
            $post = get_post(absint($_REQUEST['post_id']));
            if ($post && in_array($post->post_type, ['post', 'page'], true)) {
                return sanitize_title($post->post_name ?? 'media');
            }
        }

        return 'media';
    }

    private function get_context_title(int $attachment_id): string {
        $parent = (int) get_post_field('post_parent', $attachment_id);
        if ($parent > 0) {
            $post = get_post($parent);
            if ($post && in_array($post->post_type, ['post', 'page'], true)) {
                return (string) ($post->post_title ?? '');
            }
        }
        if (isset($_REQUEST['post_id']) && absint($_REQUEST['post_id']) > 0) {
            $post = get_post(absint($_REQUEST['post_id']));
            if ($post && in_array($post->post_type, ['post', 'page'], true)) {
                return (string) ($post->post_title ?? '');
            }
        }
        return '';
    }

    private function is_renamable(string $path): bool {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true);
    }

    /**
     * Evita doppio rename: se il filename è già nel formato sitename-slug-id.
     */
    private function already_renamed(string $basename, int $attachment_id): bool {
        return (bool) preg_match('/^.+-' . $attachment_id . '$/', $basename);
    }

    /**
     * Aggiorna i riferimenti agli URL vecchi nel contenuto dei post e nel guid.
     *
     * @param string[] $old_urls URL da sostituire
     * @param string[] $new_urls URL sostitutive (stesso ordine)
     * @param int $attachment_id ID attachment
     * @param string $old_main_url URL principale vecchio (per guid)
     * @param string $new_main_url URL principale nuovo (per guid)
     */
    private function update_content_references(
        array $old_urls,
        array $new_urls,
        int $attachment_id,
        string $old_main_url,
        string $new_main_url
    ): void {
        global $wpdb;

        $filename_base = pathinfo($old_main_url, PATHINFO_FILENAME);
        $search_base   = substr($old_main_url, 0, strrpos($old_main_url, '/') + 1) . $filename_base;
        $pattern      = '%' . $wpdb->esc_like($search_base) . '%';

        $posts = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish', 'draft', 'pending', 'private', 'future')",
            $pattern
        ));

        foreach ($posts as $post_id) {
            $content = get_post_field('post_content', (int) $post_id);
            if ($content === '') {
                continue;
            }
            $new_content = $content;
            foreach ($old_urls as $i => $old) {
                if (isset($new_urls[$i])) {
                    $new_content = str_replace($old, $new_urls[$i], $new_content);
                }
            }
            if ($new_content !== $content) {
                wp_update_post([
                    'ID'           => (int) $post_id,
                    'post_content' => $new_content,
                ]);
            }
        }

        $old_guid = $wpdb->get_var($wpdb->prepare(
            "SELECT guid FROM {$wpdb->posts} WHERE ID = %d",
            $attachment_id
        ));
        if ($old_guid && strpos($old_guid, $old_main_url) !== false) {
            $new_guid = str_replace($old_main_url, $new_main_url, $old_guid);
            $wpdb->update(
                $wpdb->posts,
                ['guid' => $new_guid],
                ['ID' => $attachment_id]
            );
        }
    }
}
