<?php

declare(strict_types=1);

namespace FP\ImgOpt\Services;

/**
 * Estrae gli ID degli allegati (immagini) dal contenuto di un post.
 *
 * Cerca img src e srcset che puntano a file nella cartella uploads.
 */
final class ContentImageExtractor {

    /**
     * Restituisce gli ID degli attachment usati nel contenuto del post.
     *
     * @param int $post_id ID del post/pagina
     * @return int[] ID allegati (senza duplicati)
     */
    public static function get_attachment_ids_from_post(int $post_id): array {
        $content = get_post_field('post_content', $post_id);
        if ($content === '') {
            return [];
        }

        $urls = [];
        if (preg_match_all('/src=["\']([^"\']+)["\']/i', $content, $m)) {
            foreach ($m[1] as $url) {
                $clean = strtok(trim($url), '?');
                if ($clean && self::is_upload_url($clean)) {
                    $urls[] = $clean;
                }
            }
        }
        if (preg_match_all('/srcset=["\']([^"\']+)["\']/i', $content, $m)) {
            foreach ($m[1] as $srcset) {
                foreach (array_map('trim', explode(',', $srcset)) as $part) {
                    if (preg_match('/^(\S+)\s+[\d.]+[wx]$/i', $part, $mm)) {
                        $u = trim($mm[1]);
                        if (self::is_upload_url($u)) {
                            $urls[] = $u;
                        }
                    } elseif ($part !== '' && self::is_upload_url($part)) {
                        $urls[] = $part;
                    }
                }
            }
        }

        $ids = [];
        foreach (array_unique($urls) as $url) {
            $id = attachment_url_to_postid($url);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function is_upload_url(string $url): bool {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return false;
        }
        $base = $upload_dir['baseurl'];
        return strpos($url, $base) === 0;
    }
}
