<?php

if (!defined('ABSPATH')) {
    exit;
}

class MMBA_Admin {

    const PAGE_SLUG = 'movie-meta-by-aris';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_filter('plugin_action_links_' . plugin_basename(MMBA_PLUGIN_FILE), [__CLASS__, 'plugin_action_links']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_mmba_sync_sheet', [__CLASS__, 'handle_sync_sheet']);
        add_action('in_admin_header', [__CLASS__, 'suppress_foreign_notices'], 1000);
        add_filter('admin_body_class', [__CLASS__, 'admin_body_class']);
    }

    public static function admin_body_class($classes) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === self::PAGE_SLUG) {
            $classes .= ' mmba-admin-page';
        }

        return $classes;
    }

    public static function suppress_foreign_notices() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG) {
            return;
        }

        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }

    public static function register_menu() {
        add_menu_page(
            __('Movie Meta', 'movie-meta-by-aris'),
            __('Movie Meta', 'movie-meta-by-aris'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page'],
            'dashicons-video-alt3',
            58
        );
    }

    public static function plugin_action_links($links) {
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '">' . esc_html__('Catalog', 'movie-meta-by-aris') . '</a>'
        );
        return $links;
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'mmba-admin',
            MMBA_PLUGIN_URL . 'assets/admin.css',
            [],
            MMBA_VERSION
        );

        wp_enqueue_script(
            'mmba-admin',
            MMBA_PLUGIN_URL . 'assets/admin.js',
            [],
            MMBA_VERSION,
            true
        );
    }

    public static function handle_sync_sheet() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to sync the catalog.', 'movie-meta-by-aris'));
        }

        check_admin_referer('mmba_sync_sheet');
        delete_transient(MMBA_Sheets::FRESH_KEY);
        delete_transient(MMBA_Sheets::TOKEN_KEY);
        $result = MMBA_Sheets::sync(true);

        $redirect = admin_url('admin.php?page=' . self::PAGE_SLUG);
        if (is_wp_error($result)) {
            $redirect = add_query_arg('mmba_error', rawurlencode($result->get_error_message()), $redirect);
        } else {
            $redirect = add_query_arg('mmba_synced', (int) $result['count'], $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $movies = MMBA_Storage::get_movies();
        $views = MMBA_Storage::get_views();
        $top_movies = MMBA_Storage::get_top_movies(10);
        $rest_url = rest_url(MMBA_API::REST_NS . '/movies');
        $json_url = MMBA_Storage::json_file_url();
        $movie_count = count($movies);
        $series_count = 0;
        foreach ($movies as $item) {
            if (isset($item['type']) && $item['type'] === 'series') {
                $series_count++;
            }
        }
        $catalog_shortcode = '[movie_meta]';
        $flash_success = '';
        $flash_error = '';
        $sheet_error = MMBA_Sheets::last_error();
        $synced_at = MMBA_Sheets::last_synced_at();

        if (!empty($_GET['mmba_synced'])) {
            $flash_success = sprintf(
                /* translators: %d: title count */
                __('Synced %d title(s) from Google Sheets.', 'movie-meta-by-aris'),
                (int) $_GET['mmba_synced']
            );
        }

        if (!empty($_GET['mmba_error'])) {
            $flash_error = rawurldecode(wp_unslash($_GET['mmba_error']));
        } elseif ($sheet_error !== '') {
            $flash_error = $sheet_error;
        }
        ?>
        <div class="wrap mmba-app">
            <div class="mmba-shell">
                <header class="mmba-hero">
                    <div class="mmba-hero-top">
                        <div class="mmba-brand">
                            <div class="mmba-mark" aria-hidden="true"><span class="dashicons dashicons-video-alt3"></span></div>
                            <div>
                                <p class="mmba-eyebrow"><?php esc_html_e('Google Sheets catalog', 'movie-meta-by-aris'); ?></p>
                                <h1><?php esc_html_e('Movie Meta', 'movie-meta-by-aris'); ?></h1>
                            </div>
                        </div>
                        <div class="mmba-hero-meta">
                            <span class="mmba-pill mmba-pill-ok">
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: %d: title count */
                                        _n('%d title', '%d titles', $movie_count, 'movie-meta-by-aris'),
                                        $movie_count
                                    )
                                );
                                ?>
                            </span>
                            <span class="mmba-version-chip">v<?php echo esc_html(MMBA_VERSION); ?></span>
                        </div>
                    </div>
                </header>

                <div class="mmba-flash-area" aria-live="polite">
                    <?php if ($flash_success !== '') : ?>
                        <div class="mmba-flash is-success"><?php echo esc_html($flash_success); ?></div>
                    <?php endif; ?>
                    <?php if ($flash_error !== '') : ?>
                        <div class="mmba-flash is-error"><?php echo esc_html($flash_error); ?></div>
                    <?php endif; ?>
                </div>

                <div class="mmba-layout">
                    <div class="mmba-main">
                        <section class="mmba-card">
                            <div class="mmba-card-head">
                                <div>
                                    <h2><?php esc_html_e('Catalog', 'movie-meta-by-aris'); ?></h2>
                                    <p class="mmba-card-lead"><?php esc_html_e('Read-only list from Google Sheets. Series are grouped by title; movies stay one row each.', 'movie-meta-by-aris'); ?></p>
                                </div>
                            </div>

                            <?php if (empty($movies)) : ?>
                                <div class="mmba-empty-state">
                                    <strong><?php esc_html_e('No titles yet', 'movie-meta-by-aris'); ?></strong>
                                    <?php esc_html_e('Share the spreadsheet with the service account, then sync.', 'movie-meta-by-aris'); ?>
                                </div>
                            <?php else : ?>
                                <div class="mmba-table-wrap">
                                    <table class="mmba-table">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e('Type', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Title', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Year', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Genre', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Episodes', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Views', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Shortcode', 'movie-meta-by-aris'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($movies as $movie) : ?>
                                                <?php
                                                $movie_shortcode = '[movie_meta id="' . $movie['id'] . '"]';
                                                $is_series = isset($movie['type']) && $movie['type'] === 'series';
                                                ?>
                                                <tr>
                                                    <td><?php echo esc_html($is_series ? __('Series', 'movie-meta-by-aris') : __('Movie', 'movie-meta-by-aris')); ?></td>
                                                    <td class="mmba-title-cell"><?php echo esc_html($movie['title']); ?></td>
                                                    <td><?php echo esc_html($movie['year']); ?></td>
                                                    <td><?php echo esc_html($movie['genre']); ?></td>
                                                    <td><?php echo $is_series ? esc_html((string) (int) $movie['episode_count']) : '—'; ?></td>
                                                    <td><?php echo esc_html(number_format_i18n(isset($views[$movie['id']]) ? (int) $views[$movie['id']] : 0)); ?></td>
                                                    <td>
                                                        <div class="mmba-copy-row">
                                                            <code><?php echo esc_html($movie_shortcode); ?></code>
                                                            <button type="button" class="mmba-btn mmba-btn-ghost mmba-btn-sm mmba-copy-btn" data-target="<?php echo esc_attr($movie_shortcode); ?>">
                                                                <?php esc_html_e('Copy', 'movie-meta-by-aris'); ?>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>

                    <aside class="mmba-sidebar">
                        <section class="mmba-card mmba-card-compact">
                            <div class="mmba-card-head mmba-card-head-tight">
                                <h2><?php esc_html_e('Google Sheets', 'movie-meta-by-aris'); ?></h2>
                            </div>
                            <p class="mmba-help">
                                <?php esc_html_e('1) Enable the Google Sheets API on the GCP project. 2) Share the spreadsheet (Viewer) with:', 'movie-meta-by-aris'); ?>
                                <code><?php echo esc_html(MMBA_Sheets::service_email()); ?></code>
                            </p>
                            <dl class="mmba-meta">
                                <div class="mmba-meta-row">
                                    <dt><?php esc_html_e('Titles', 'movie-meta-by-aris'); ?></dt>
                                    <dd><?php echo esc_html((string) $movie_count); ?></dd>
                                </div>
                                <div class="mmba-meta-row">
                                    <dt><?php esc_html_e('Series', 'movie-meta-by-aris'); ?></dt>
                                    <dd><?php echo esc_html((string) $series_count); ?></dd>
                                </div>
                                <div class="mmba-meta-row">
                                    <dt><?php esc_html_e('Last sync', 'movie-meta-by-aris'); ?></dt>
                                    <dd><?php echo $synced_at !== '' ? esc_html(date_i18n('Y-m-d H:i', strtotime($synced_at))) : esc_html__('Never', 'movie-meta-by-aris'); ?></dd>
                                </div>
                            </dl>
                            <div class="mmba-stack">
                                <a class="mmba-btn mmba-btn-secondary mmba-btn-block" href="<?php echo esc_url(MMBA_Sheets::spreadsheet_url()); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e('Open spreadsheet', 'movie-meta-by-aris'); ?>
                                </a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="mmba_sync_sheet">
                                    <?php wp_nonce_field('mmba_sync_sheet'); ?>
                                    <button type="submit" class="mmba-btn mmba-btn-primary mmba-btn-block">
                                        <?php esc_html_e('Sync now', 'movie-meta-by-aris'); ?>
                                    </button>
                                </form>
                            </div>
                        </section>

                        <section class="mmba-card mmba-card-compact">
                            <div class="mmba-card-head mmba-card-head-tight">
                                <h2><?php esc_html_e('Top 10', 'movie-meta-by-aris'); ?></h2>
                            </div>
                            <?php if (empty($top_movies)) : ?>
                                <p class="mmba-help"><?php esc_html_e('No titles in the catalog yet.', 'movie-meta-by-aris'); ?></p>
                            <?php else : ?>
                                <ol class="mmba-top-list">
                                    <?php foreach ($top_movies as $index => $top) : ?>
                                        <?php
                                        $top_views = isset($top['views']) ? (int) $top['views'] : 0;
                                        $top_title = isset($top['title']) && $top['title'] !== '' ? $top['title'] : __('Untitled', 'movie-meta-by-aris');
                                        ?>
                                        <li class="mmba-top-item">
                                            <span class="mmba-top-rank"><?php echo esc_html((string) ($index + 1)); ?></span>
                                            <span class="mmba-top-title" title="<?php echo esc_attr($top_title); ?>"><?php echo esc_html($top_title); ?></span>
                                            <span class="mmba-top-views<?php echo $top_views === 0 ? ' is-zero' : ''; ?>">
                                                <?php echo esc_html(number_format_i18n($top_views)); ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </section>

                        <section class="mmba-card mmba-card-compact">
                            <div class="mmba-card-head mmba-card-head-tight">
                                <h2><?php esc_html_e('Shortcode', 'movie-meta-by-aris'); ?></h2>
                            </div>
                            <div class="mmba-shortcode-stack">
                                <div class="mmba-shortcode-item">
                                    <span><?php esc_html_e('All titles', 'movie-meta-by-aris'); ?></span>
                                    <div class="mmba-copy-row">
                                        <code><?php echo esc_html($catalog_shortcode); ?></code>
                                        <button type="button" class="mmba-btn mmba-btn-ghost mmba-btn-sm mmba-copy-btn" data-target="<?php echo esc_attr($catalog_shortcode); ?>">
                                            <?php esc_html_e('Copy', 'movie-meta-by-aris'); ?>
                                        </button>
                                    </div>
                                </div>
                                <div class="mmba-shortcode-item">
                                    <span><?php esc_html_e('Top 10', 'movie-meta-by-aris'); ?></span>
                                    <div class="mmba-copy-row">
                                        <code>[movie_top10]</code>
                                        <button type="button" class="mmba-btn mmba-btn-ghost mmba-btn-sm mmba-copy-btn" data-target="[movie_top10]">
                                            <?php esc_html_e('Copy', 'movie-meta-by-aris'); ?>
                                        </button>
                                    </div>
                                </div>
                                <div class="mmba-shortcode-item">
                                    <span><?php esc_html_e('Just Added', 'movie-meta-by-aris'); ?></span>
                                    <div class="mmba-copy-row">
                                        <code>[movie_just_added]</code>
                                        <button type="button" class="mmba-btn mmba-btn-ghost mmba-btn-sm mmba-copy-btn" data-target="[movie_just_added]">
                                            <?php esc_html_e('Copy', 'movie-meta-by-aris'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="mmba-card mmba-card-compact">
                            <div class="mmba-card-head mmba-card-head-tight">
                                <h2><?php esc_html_e('API', 'movie-meta-by-aris'); ?></h2>
                            </div>
                            <div class="mmba-shortcode-stack mmba-shortcode-stack-spaced">
                                <div class="mmba-shortcode-item">
                                    <span><?php esc_html_e('REST', 'movie-meta-by-aris'); ?></span>
                                    <div class="mmba-copy-row">
                                        <code><?php echo esc_html($rest_url); ?></code>
                                        <button type="button" class="mmba-btn mmba-btn-ghost mmba-btn-sm mmba-copy-btn" data-target="<?php echo esc_attr($rest_url); ?>">
                                            <?php esc_html_e('Copy', 'movie-meta-by-aris'); ?>
                                        </button>
                                    </div>
                                </div>
                                <?php if ($json_url !== '') : ?>
                                    <div class="mmba-shortcode-item">
                                        <span><?php esc_html_e('JSON file', 'movie-meta-by-aris'); ?></span>
                                        <div class="mmba-copy-row">
                                            <code><?php echo esc_html($json_url); ?></code>
                                            <button type="button" class="mmba-btn mmba-btn-ghost mmba-btn-sm mmba-copy-btn" data-target="<?php echo esc_attr($json_url); ?>">
                                                <?php esc_html_e('Copy', 'movie-meta-by-aris'); ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </aside>
                </div>
            </div>

            <footer class="mmba-footer">
                <span><?php esc_html_e('Developed by Aris', 'movie-meta-by-aris'); ?></span>
            </footer>
        </div>
        <?php
    }
}
