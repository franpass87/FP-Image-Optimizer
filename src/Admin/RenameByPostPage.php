<?php

declare(strict_types=1);

namespace FP\ImgOpt\Admin;

use FP\ImgOpt\Services\ContentImageExtractor;

/**
 * Pagina admin "Rinomina per pagina/articolo" con tab Pagine e Articoli.
 *
 * Permette di rinominare one-click le immagini contenute in una pagina o articolo
 * secondo il formato sitename-slug-id.
 */
final class RenameByPostPage {

    public const PAGE_SLUG = 'fp-imgopt-rename-by-post';

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function render(): void {
        $tab = isset($_GET['tab']) && sanitize_text_field(wp_unslash($_GET['tab'])) === 'articoli' ? 'articoli' : 'pagine';
        $post_type = $tab === 'articoli' ? 'post' : 'page';

        $posts = get_posts([
            'post_type'      => $post_type,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 100,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);
        ?>
<div class="wrap fpimgopt-admin-page">

    <h1 class="screen-reader-text"><?php echo esc_html__('Rinomina immagini per pagina/articolo', 'fp-imgopt'); ?></h1>

    <div class="fpimgopt-page-header">
        <div class="fpimgopt-page-header-content">
            <h2 class="fpimgopt-page-header-title" aria-hidden="true">
                <span class="dashicons dashicons-edit-page"></span>
                <?php echo esc_html__('Rinomina immagini per pagina/articolo', 'fp-imgopt'); ?>
            </h2>
            <p><?php echo esc_html__('Rinomina one-click le immagini contenute in una pagina o articolo secondo il formato nome-sito-slug-id.', 'fp-imgopt'); ?></p>
        </div>
    </div>

    <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=pagine')); ?>"
           class="nav-tab <?php echo $tab === 'pagine' ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html__('Pagine', 'fp-imgopt'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=articoli')); ?>"
           class="nav-tab <?php echo $tab === 'articoli' ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html__('Articoli', 'fp-imgopt'); ?>
        </a>
    </nav>

    <div class="fpimgopt-card">
        <div class="fpimgopt-card-header">
            <div class="fpimgopt-card-header-left">
                <span class="dashicons dashicons-<?php echo $tab === 'pagine' ? 'admin-page' : 'admin-post'; ?>"></span>
                <h2><?php echo $tab === 'pagine' ? esc_html__('Pagine', 'fp-imgopt') : esc_html__('Articoli', 'fp-imgopt'); ?></h2>
            </div>
        </div>
        <div class="fpimgopt-card-body">
            <p class="description"><?php echo esc_html__('Clicca "Rinomina immagini" per rinominare tutte le immagini contenute nel contenuto della pagina/articolo.', 'fp-imgopt'); ?></p>

            <?php if (empty($posts)) : ?>
                <p><?php echo esc_html__('Nessun contenuto trovato.', 'fp-imgopt'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Titolo', 'fp-imgopt'); ?></th>
                            <th><?php echo esc_html__('Immagini', 'fp-imgopt'); ?></th>
                            <th><?php echo esc_html__('Azioni', 'fp-imgopt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $post) :
                            $ids = ContentImageExtractor::get_attachment_ids_from_post($post->ID);
                            $to_rename = [];
                            foreach ($ids as $aid) {
                                $path = get_attached_file($aid);
                                if ($path && is_file($path)) {
                                    $bn = pathinfo($path, PATHINFO_FILENAME);
                                    if (!(bool) preg_match('/^.+-' . $aid . '$/', $bn)) {
                                        $to_rename[] = $aid;
                                    }
                                }
                            }
                            ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">
                                        <?php echo esc_html($post->post_title ?: __('(senza titolo)', 'fp-imgopt')); ?>
                                    </a>
                                </strong>
                            </td>
                            <td>
                                <?php echo count($ids); ?>
                                <?php if (!empty($to_rename)) : ?>
                                    <span class="description">(<?php echo count($to_rename); ?> da rinominare)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($to_rename)) : ?>
                                    <span class="description"><?php echo esc_html__('Tutte già rinominate', 'fp-imgopt'); ?></span>
                                <?php else : ?>
                                    <button type="button"
                                            class="fpimgopt-btn fpimgopt-btn-primary fpimgopt-btn-sm fpimgopt-rename-post-btn"
                                            data-post-id="<?php echo (int) $post->ID; ?>"
                                            data-post-title="<?php echo esc_attr($post->post_title ?: __('(senza titolo)', 'fp-imgopt')); ?>">
                                        <span class="dashicons dashicons-edit"></span>
                                        <?php echo esc_html__('Rinomina immagini', 'fp-imgopt'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>
        <?php
    }
}
