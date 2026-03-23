<?php

declare(strict_types=1);

namespace FP\ImgOpt\Services;

use FP\ImgOpt\Admin\Settings;
use WP_Error;

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

        if (str_contains($main_file, '..')) {
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
        $rollback_pairs = []; // [(from_path, to_path)] in ordine per rollback inverso

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $key => $size_data) {
                $old_file = $size_data['file'] ?? '';
                if (empty($old_file)) {
                    continue;
                }
                $old_file = basename($old_file);
                if ($old_file === '' || str_contains($old_file, '..')) {
                    continue;
                }
                $old_full = $full_dir . $old_file;
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
                    $rollback_pairs[] = [$size_path_new, $old_full];
                } else {
                    $this->rollback_renames($new_path, $main_path, $rollback_pairs);
                    return $metadata;
                }
            }
        }

        $metadata['file'] = $new_rel;
        update_attached_file($attachment_id, $new_path);

        $this->update_content_references($old_urls, $new_urls, $attachment_id, $base_url . $main_file, $base_url . $new_rel);

        return $metadata;
    }

    /**
     * Rinomina un attachment usando un post specifico come contesto (per slug).
     * Usato dalla pagina "Rinomina per pagina/articolo" per rename one-click.
     *
     * @param int $attachment_id ID dell'allegato
     * @param int|null $context_post_id ID del post/pagina da cui prendere lo slug (null = usa parent o media)
     * @return array{renamed: bool, message: string}|WP_Error
     */
    public function rename_attachment_for_post(int $attachment_id, ?int $context_post_id = null): array|WP_Error {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata) || empty($metadata['file'])) {
            return new WP_Error('fp_imgopt_no_metadata', __('Metadata allegato non disponibile.', 'fp-imgopt'));
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('fp_imgopt_upload_dir', $upload_dir['error']);
        }

        $base_dir  = trailingslashit($upload_dir['basedir']);
        $base_url  = trailingslashit($upload_dir['baseurl']);
        $rel_dir   = dirname($metadata['file'] ?? '');
        $full_dir  = $base_dir . $rel_dir . '/';
        $main_file = $metadata['file'] ?? '';

        if (str_contains($main_file, '..')) {
            return new WP_Error('fp_imgopt_invalid_path', __('Percorso non valido.', 'fp-imgopt'));
        }
        $main_path = $base_dir . $main_file;
        if (!is_file($main_path) || !$this->is_renamable($main_path)) {
            return new WP_Error('fp_imgopt_not_renamable', __('File non rinominabile.', 'fp-imgopt'));
        }

        $current_basename = pathinfo($main_file, PATHINFO_FILENAME);
        if ($this->already_renamed($current_basename, $attachment_id)) {
            return ['renamed' => false, 'message' => __('Già nel formato atteso.', 'fp-imgopt')];
        }

        $base_name = $this->build_base_name_with_context($attachment_id, $context_post_id);
        $ext       = strtolower(pathinfo($main_file, PATHINFO_EXTENSION));
        $new_name  = $base_name . '.' . $ext;
        $new_name  = wp_unique_filename($full_dir, $new_name);

        $new_rel   = $rel_dir ? $rel_dir . '/' . $new_name : $new_name;
        $new_path  = $base_dir . $new_rel;

        if ($new_path === $main_path) {
            return ['renamed' => false, 'message' => __('Nessuna modifica necessaria.', 'fp-imgopt')];
        }

        if (!rename($main_path, $new_path)) {
            return new WP_Error('fp_imgopt_rename_failed', __('Errore durante il rinomina.', 'fp-imgopt'));
        }

        $old_urls = [$base_url . $main_file];
        $new_urls = [$base_url . $new_rel];
        $rollback_pairs = [];

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $key => $size_data) {
                $old_file = $size_data['file'] ?? '';
                if (empty($old_file)) {
                    continue;
                }
                $old_file = basename($old_file);
                if ($old_file === '' || str_contains($old_file, '..')) {
                    continue;
                }
                $old_full     = $full_dir . $old_file;
                $size_ext     = strtolower(pathinfo($old_file, PATHINFO_EXTENSION));
                $size_new     = $base_name . '-' . $key . '.' . $size_ext;
                $size_new     = wp_unique_filename($full_dir, $size_new);
                $size_path_new = $full_dir . $size_new;

                if (is_file($old_full) && rename($old_full, $size_path_new)) {
                    $metadata['sizes'][$key]['file'] = $size_new;
                    $old_rel = $rel_dir ? $rel_dir . '/' . $old_file : $old_file;
                    $new_rel_size = $rel_dir ? $rel_dir . '/' . $size_new : $size_new;
                    $old_urls[] = $base_url . $old_rel;
                    $new_urls[] = $base_url . $new_rel_size;
                    $rollback_pairs[] = [$size_path_new, $old_full];
                    $size_old_base = pathinfo($old_file, PATHINFO_FILENAME);
                    $this->rename_variant_files($full_dir, $size_old_base, $base_name . '-' . $key);
                } else {
                    $this->rollback_renames($new_path, $main_path, $rollback_pairs);
                    return new WP_Error('fp_imgopt_rename_failed', __('Errore rinomina dimensioni.', 'fp-imgopt'));
                }
            }
        }

        $this->rename_variant_files($full_dir, $current_basename, $base_name);

        $metadata['file'] = $new_rel;
        wp_update_attachment_metadata($attachment_id, $metadata);
        update_attached_file($attachment_id, $new_path);

        $this->update_content_references($old_urls, $new_urls, $attachment_id, $base_url . $main_file, $base_url . $new_rel);

        return ['renamed' => true, 'message' => sprintf(__('Rinominato in %s', 'fp-imgopt'), $new_name)];
    }

    /**
     * Rinomina i file varianti WebP e AVIF quando si rinomina l'immagine principale.
     *
     * @param string $dir Directory
     * @param string $old_basename Nome file senza estensione (vecchio)
     * @param string $new_basename Nome file senza estensione (nuovo)
     */
    private function rename_variant_files(string $dir, string $old_basename, string $new_basename): void {
        foreach (['webp', 'avif'] as $ext) {
            $old_path = $dir . $old_basename . '.' . $ext;
            $new_path = $dir . $new_basename . '.' . $ext;
            if (is_file($old_path) && $old_path !== $new_path && !is_file($new_path)) {
                @rename($old_path, $new_path);
            }
        }
    }

    private function build_base_name_with_context(int $attachment_id, ?int $context_post_id): string {
        $site = sanitize_title(get_bloginfo('name'));
        $site = substr($site, 0, self::MAX_SITENAME_LENGTH) ?: 'sito';

        $slug = 'media';
        if ($context_post_id) {
            $post = get_post($context_post_id);
            if ($post && in_array($post->post_type, ['post', 'page'], true)) {
                $slug = sanitize_title($post->post_name ?? 'media');
            }
        }
        if ($slug === 'media') {
            $slug = $this->get_context_slug($attachment_id);
        }
        $slug = substr($slug, 0, self::MAX_SLUG_LENGTH) ?: 'media';

        return $site . '-' . $slug . '-' . $attachment_id;
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
     * Rollback completo: ripristina main file e tutte le dimensioni rinominate.
     *
     * @param string $main_new_path Path del main file dopo rename
     * @param string $main_old_path Path originale del main file
     * @param array<int, array{0: string, 1: string}> $size_rollback_pairs Coppie [new_path, old_path] per ogni size
     */
    private function rollback_renames(string $main_new_path, string $main_old_path, array $size_rollback_pairs): void {
        foreach (array_reverse($size_rollback_pairs) as [$from, $to]) {
            if (is_file($from)) {
                rename($from, $to);
            }
        }
        if (is_file($main_new_path)) {
            rename($main_new_path, $main_old_path);
        }
    }

    /**
     * Evita doppio rename: se il filename è già nel formato sitename-slug-id.
     */
    private function already_renamed(string $basename, int $attachment_id): bool {
        // Considera validi anche i filename univoci creati da WP: ...-ID-1, ...-ID-2, ecc.
        return (bool) preg_match('/^.+-' . $attachment_id . '(?:-\d+)?$/', $basename);
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

        $last_slash = strrpos($old_main_url, '/');
        if ($last_slash === false) {
            return;
        }
        $filename_base = pathinfo($old_main_url, PATHINFO_FILENAME);
        $search_base   = substr($old_main_url, 0, $last_slash + 1) . $filename_base;
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
