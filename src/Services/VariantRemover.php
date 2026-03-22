<?php

declare(strict_types=1);

namespace FP\ImgOpt\Services;

/**
 * Rimuove i file WebP e AVIF generati dal plugin (rollback/test).
 *
 * Elimina solo file .webp e .avif accanto a immagini JPG/PNG/GIF nella Media Library.
 */
final class VariantRemover {

    /**
     * Elimina tutte le varianti WebP/AVIF dalle immagini della Media Library.
     *
     * @return array{deleted: int, errors: int}
     */
    public function remove_all_variants(): array {
        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return ['deleted' => 0, 'errors' => 0];
        }

        $base_dir   = trailingslashit($upload_dir['basedir']);
        $base_real  = realpath($base_dir) ?: $base_dir;
        $deleted    = 0;
        $errors     = 0;
        $seen_paths = [];

        foreach ($ids as $attachment_id) {
            $result = $this->remove_attachment_variants((int) $attachment_id, $base_dir, $base_real, $seen_paths);
            $deleted += $result['deleted'];
            $errors  += $result['errors'];
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * @param array<string, true> $seen_paths Path già processati (evita duplicati tra main e sizes)
     * @return array{deleted: int, errors: int}
     */
    private function remove_attachment_variants(
        int $attachment_id,
        string $base_dir,
        string $base_real,
        array &$seen_paths
    ): array {
        $path = get_attached_file($attachment_id);
        if (!$path || !is_file($path) || strpos(realpath($path) ?: $path, $base_real) !== 0) {
            return ['deleted' => 0, 'errors' => 0];
        }

        $path_info = pathinfo($path);
        $dir       = $path_info['dirname'] . '/';
        $filename  = $path_info['filename'];
        $ext       = strtolower($path_info['extension'] ?? '');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return ['deleted' => 0, 'errors' => 0];
        }

        $deleted = 0;
        $errors  = 0;

        foreach (['.webp', '.avif'] as $suffix) {
            $variant = $dir . $filename . $suffix;
            if (isset($seen_paths[$variant])) {
                continue;
            }
            $seen_paths[$variant] = true;
            if (is_file($variant)) {
                if (@unlink($variant)) {
                    $deleted++;
                } else {
                    $errors++;
                }
            }
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $s) {
                $sf = $s['file'] ?? '';
                if ($sf === '') {
                    continue;
                }
                $sf        = basename($sf);
                $path_info_s = pathinfo($sf);
                $fn_s        = $path_info_s['filename'] ?? '';
                if ($fn_s === '') {
                    continue;
                }
                foreach (['.webp', '.avif'] as $suffix) {
                    $variant = $dir . $fn_s . $suffix;
                    if (isset($seen_paths[$variant])) {
                        continue;
                    }
                    $seen_paths[$variant] = true;
                    if (is_file($variant)) {
                        if (@unlink($variant)) {
                            $deleted++;
                        } else {
                            $errors++;
                        }
                    }
                }
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }
}
