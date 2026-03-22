<?php

declare(strict_types=1);

namespace FP\ImgOpt\Services;

/**
 * Genera attributi SEO per le immagini: alt, title, caption.
 */
final class ImageSeoHelper {

    private const MAX_ALT_LENGTH = 125;

    /**
     * Aggiorna alt, title e caption dell'attachment se vuoti.
     *
     * @param int $attachment_id ID attachment
     * @param string $context_title Titolo del contesto (es. titolo post/pagina)
     * @param string $context_slug Slug del contesto (es. "contatti")
     */
    public static function ensure_seo_attributes(
        int $attachment_id,
        string $context_title,
        string $context_slug
    ): void {
        $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if (is_string($alt) && trim($alt) !== '') {
            return;
        }

        $generated = self::generate_alt_from_context($context_title, $context_slug);

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $generated);

        $post = get_post($attachment_id);
        if ($post && trim($post->post_title ?? '') === '') {
            wp_update_post([
                'ID'         => $attachment_id,
                'post_title' => $generated,
            ]);
        }

        if ($post && trim($post->post_excerpt ?? '') === '') {
            wp_update_post([
                'ID'           => $attachment_id,
                'post_excerpt' => $generated,
            ]);
        }
    }

    /**
     * Converte slug in testo leggibile per alt (es. "chi-siamo" → "Chi siamo").
     */
    public static function slug_to_readable(string $slug): string {
        $parts = preg_split('/[-_]+/', $slug, -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_map('ucfirst', array_map('strtolower', $parts));
        return implode(' ', $parts);
    }

    private static function generate_alt_from_context(string $title, string $slug): string {
        $candidate = trim($title) !== '' ? $title : self::slug_to_readable($slug);
        $site      = get_bloginfo('name');
        $result    = $candidate;
        if (trim($site) !== '' && $candidate !== $site) {
            $result = $candidate . ' - ' . $site;
        }
        return substr(sanitize_text_field($result), 0, self::MAX_ALT_LENGTH);
    }
}
