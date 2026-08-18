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
        add_action('admin_post_mmba_save_movie', [__CLASS__, 'handle_save_movie']);
        add_action('admin_post_mmba_delete_movie', [__CLASS__, 'handle_delete_movie']);
        add_action('admin_post_mmba_export_json', [__CLASS__, 'handle_export_json']);
        add_action('admin_post_mmba_import_json', [__CLASS__, 'handle_import_json']);
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

    /** Keep this screen free of other plugins' admin notices. */
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
            '<a href="' . esc_url($url) . '">' . esc_html__('Manage Movies', 'movie-meta-by-aris') . '</a>'
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

    public static function handle_save_movie() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage movies.', 'movie-meta-by-aris'));
        }

        check_admin_referer('mmba_save_movie');

        $id = isset($_POST['movie_id']) ? sanitize_text_field(wp_unslash($_POST['movie_id'])) : '';
        $input = [
            'title'      => isset($_POST['title']) ? $_POST['title'] : '',
            'details'    => isset($_POST['details']) ? $_POST['details'] : '',
            'cast'       => isset($_POST['cast']) ? $_POST['cast'] : '',
            'year'       => isset($_POST['year']) ? $_POST['year'] : '',
            'movie_link' => isset($_POST['movie_link']) ? $_POST['movie_link'] : '',
            'genre'      => isset($_POST['genre']) ? $_POST['genre'] : '',
        ];

        $result = $id !== ''
            ? MMBA_Storage::update_movie($id, $input)
            : MMBA_Storage::add_movie($input);

        $redirect = admin_url('admin.php?page=' . self::PAGE_SLUG);

        if (is_wp_error($result)) {
            $redirect = add_query_arg([
                'mmba_error' => rawurlencode($result->get_error_message()),
                'edit'       => $id,
            ], $redirect);
        } else {
            $redirect = add_query_arg('mmba_saved', '1', $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_delete_movie() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage movies.', 'movie-meta-by-aris'));
        }

        check_admin_referer('mmba_delete_movie');

        $id = isset($_GET['movie_id']) ? sanitize_text_field(wp_unslash($_GET['movie_id'])) : '';
        $result = MMBA_Storage::delete_movie($id);

        $redirect = admin_url('admin.php?page=' . self::PAGE_SLUG);
        if (is_wp_error($result)) {
            $redirect = add_query_arg('mmba_error', rawurlencode($result->get_error_message()), $redirect);
        } else {
            $redirect = add_query_arg('mmba_deleted', '1', $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_export_json() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to export movies.', 'movie-meta-by-aris'));
        }

        check_admin_referer('mmba_export_json');

        $payload = MMBA_Storage::export_payload();
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            wp_die(esc_html__('Failed to encode JSON.', 'movie-meta-by-aris'));
        }

        $filename = 'movie-meta-export-' . gmdate('Ymd-His') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));

        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function handle_import_json() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to import movies.', 'movie-meta-by-aris'));
        }

        check_admin_referer('mmba_import_json');

        $redirect = admin_url('admin.php?page=' . self::PAGE_SLUG);
        $mode = isset($_POST['import_mode']) && $_POST['import_mode'] === 'replace' ? 'replace' : 'merge';

        if (empty($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('mmba_error', rawurlencode(__('Please choose a JSON file to import.', 'movie-meta-by-aris')), $redirect));
            exit;
        }

        $name = isset($_FILES['import_file']['name']) ? (string) $_FILES['import_file']['name'] : '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            wp_safe_redirect(add_query_arg('mmba_error', rawurlencode(__('Only .json files are allowed.', 'movie-meta-by-aris')), $redirect));
            exit;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents($_FILES['import_file']['tmp_name']);
        if ($raw === false || $raw === '') {
            wp_safe_redirect(add_query_arg('mmba_error', rawurlencode(__('Could not read the uploaded file.', 'movie-meta-by-aris')), $redirect));
            exit;
        }

        $result = MMBA_Storage::import_from_data($raw, $mode);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('mmba_error', rawurlencode($result->get_error_message()), $redirect));
            exit;
        }

        wp_safe_redirect(add_query_arg([
            'mmba_imported' => (int) $result['count'],
            'mmba_mode'     => $result['mode'],
        ], $redirect));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $movies = MMBA_Storage::get_movies();
        $views = MMBA_Storage::get_views();
        $top_movies = MMBA_Storage::get_top_movies(10);
        $edit_id = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : '';
        $editing = $edit_id !== '' ? MMBA_Storage::get_movie($edit_id) : null;

        $form = [
            'id'         => $editing ? $editing['id'] : '',
            'title'      => $editing ? $editing['title'] : '',
            'details'    => $editing ? $editing['details'] : '',
            'cast'       => $editing ? $editing['cast'] : '',
            'year'       => $editing ? $editing['year'] : '',
            'movie_link' => $editing ? $editing['movie_link'] : '',
            'genre'      => $editing ? $editing['genre'] : '',
        ];

        $rest_url = rest_url(MMBA_API::REST_NS . '/movies');
        $json_url = MMBA_Storage::json_file_url();
        $movie_count = count($movies);
        $catalog_shortcode = '[movie_meta]';
        $flash_success = '';
        $flash_error = '';

        if (!empty($_GET['mmba_saved'])) {
            $flash_success = __('Movie saved successfully.', 'movie-meta-by-aris');
        } elseif (!empty($_GET['mmba_deleted'])) {
            $flash_success = __('Movie deleted.', 'movie-meta-by-aris');
        } elseif (!empty($_GET['mmba_imported'])) {
            $flash_success = sprintf(
                /* translators: 1: imported count, 2: mode */
                __('Imported %1$d movie(s) (%2$s).', 'movie-meta-by-aris'),
                (int) $_GET['mmba_imported'],
                sanitize_key(wp_unslash($_GET['mmba_mode'] ?? 'merge'))
            );
        }

        if (!empty($_GET['mmba_error'])) {
            $flash_error = rawurldecode(wp_unslash($_GET['mmba_error']));
        }
        ?>
        <div class="wrap mmba-app">
            <div class="mmba-shell">
                <header class="mmba-hero">
                    <div class="mmba-hero-top">
                        <div class="mmba-brand">
                            <div class="mmba-mark" aria-hidden="true"><span class="dashicons dashicons-video-alt3"></span></div>
                            <div>
                                <p class="mmba-eyebrow"><?php esc_html_e('Movie Catalog', 'movie-meta-by-aris'); ?></p>
                                <h1><?php esc_html_e('Movie Meta', 'movie-meta-by-aris'); ?></h1>
                            </div>
                        </div>
                        <div class="mmba-hero-meta">
                            <?php if ($editing) : ?>
                                <span class="mmba-pill mmba-pill-edit"><?php esc_html_e('Editing', 'movie-meta-by-aris'); ?></span>
                            <?php else : ?>
                                <span class="mmba-pill mmba-pill-ok">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %d: movie count */
                                            _n('%d movie', '%d movies', $movie_count, 'movie-meta-by-aris'),
                                            $movie_count
                                        )
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
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
                                    <h2><?php echo $editing ? esc_html__('Edit movie', 'movie-meta-by-aris') : esc_html__('Add movie', 'movie-meta-by-aris'); ?></h2>
                                    <p class="mmba-card-lead">
                                        <?php echo $editing
                                            ? esc_html__('Update the selected title, then save to apply changes.', 'movie-meta-by-aris')
                                            : esc_html__('Enter title, metadata, and playback URL to add a title to the catalog.', 'movie-meta-by-aris'); ?>
                                    </p>
                                </div>
                            </div>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mmba-form">
                                <input type="hidden" name="action" value="mmba_save_movie">
                                <?php wp_nonce_field('mmba_save_movie'); ?>
                                <?php if ($form['id'] !== '') : ?>
                                    <input type="hidden" name="movie_id" value="<?php echo esc_attr($form['id']); ?>">
                                <?php endif; ?>

                                <div class="mmba-form-grid">
                                    <div class="mmba-field mmba-field-span">
                                        <label for="mmba-title"><?php esc_html_e('Title', 'movie-meta-by-aris'); ?></label>
                                        <input type="text" id="mmba-title" name="title" value="<?php echo esc_attr($form['title']); ?>" required>
                                    </div>
                                    <div class="mmba-field">
                                        <label for="mmba-year"><?php esc_html_e('Year', 'movie-meta-by-aris'); ?></label>
                                        <input type="text" id="mmba-year" name="year" value="<?php echo esc_attr($form['year']); ?>" placeholder="2024" maxlength="9">
                                    </div>
                                    <div class="mmba-field">
                                        <label for="mmba-genre"><?php esc_html_e('Genre', 'movie-meta-by-aris'); ?></label>
                                        <input type="text" id="mmba-genre" name="genre" value="<?php echo esc_attr($form['genre']); ?>" placeholder="Action, Drama">
                                    </div>
                                    <div class="mmba-field mmba-field-span">
                                        <label for="mmba-cast"><?php esc_html_e('Cast', 'movie-meta-by-aris'); ?></label>
                                        <input type="text" id="mmba-cast" name="cast" value="<?php echo esc_attr($form['cast']); ?>" placeholder="Actor One, Actor Two">
                                    </div>
                                    <div class="mmba-field mmba-field-span">
                                        <label for="mmba-movie-link"><?php esc_html_e('Movie link', 'movie-meta-by-aris'); ?></label>
                                        <input type="text" id="mmba-movie-link" name="movie_link" value="<?php echo esc_attr($form['movie_link']); ?>" placeholder="https://" required autocomplete="off" spellcheck="false">
                                    </div>
                                    <div class="mmba-field mmba-field-span">
                                        <label for="mmba-details"><?php esc_html_e('Details', 'movie-meta-by-aris'); ?></label>
                                        <textarea id="mmba-details" name="details" rows="5"><?php echo esc_textarea($form['details']); ?></textarea>
                                    </div>
                                </div>

                                <div class="mmba-action-bar">
                                    <?php if ($editing) : ?>
                                        <a class="mmba-btn mmba-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">
                                            <?php esc_html_e('Cancel', 'movie-meta-by-aris'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <button type="submit" class="mmba-btn mmba-btn-primary">
                                        <?php echo $editing ? esc_html__('Update movie', 'movie-meta-by-aris') : esc_html__('Add movie', 'movie-meta-by-aris'); ?>
                                    </button>
                                </div>
                            </form>
                        </section>

                        <section class="mmba-card">
                            <div class="mmba-card-head">
                                <div>
                                    <h2><?php esc_html_e('Catalog', 'movie-meta-by-aris'); ?></h2>
                                    <p class="mmba-card-lead"><?php esc_html_e('Saved titles available to shortcodes and the JSON API.', 'movie-meta-by-aris'); ?></p>
                                </div>
                            </div>

                            <?php if (empty($movies)) : ?>
                                <div class="mmba-empty-state">
                                    <strong><?php esc_html_e('No movies yet', 'movie-meta-by-aris'); ?></strong>
                                    <?php esc_html_e('Add a title using the form above.', 'movie-meta-by-aris'); ?>
                                </div>
                            <?php else : ?>
                                <div class="mmba-table-wrap">
                                    <table class="mmba-table">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e('Title', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Year', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Genre', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Views', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Cast', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Shortcode', 'movie-meta-by-aris'); ?></th>
                                                <th><?php esc_html_e('Actions', 'movie-meta-by-aris'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($movies as $movie) : ?>
                                                <?php $movie_shortcode = '[movie_meta id="' . $movie['id'] . '"]'; ?>
                                                <tr>
                                                    <td class="mmba-title-cell"><?php echo esc_html($movie['title']); ?></td>
                                                    <td><?php echo esc_html($movie['year']); ?></td>
                                                    <td><?php echo esc_html($movie['genre']); ?></td>
                                                    <td><?php echo esc_html(number_format_i18n(isset($views[$movie['id']]) ? (int) $views[$movie['id']] : 0)); ?></td>
                                                    <td><?php echo esc_html(wp_trim_words($movie['cast'], 8)); ?></td>
                                                    <td>
                                                        <div class="mmba-copy-row">
                                                            <code><?php echo esc_html($movie_shortcode); ?></code>
                                                            <button type="button" class="mmba-btn mmba-btn-ghost mmba-btn-sm mmba-copy-btn" data-target="<?php echo esc_attr($movie_shortcode); ?>">
                                                                <?php esc_html_e('Copy', 'movie-meta-by-aris'); ?>
                                                            </button>
                                                        </div>
                                                    </td>
                                                    <td class="mmba-actions">
                                                        <a class="mmba-btn mmba-btn-secondary mmba-btn-sm" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&edit=' . rawurlencode($movie['id']))); ?>">
                                                            <?php esc_html_e('Edit', 'movie-meta-by-aris'); ?>
                                                        </a>
                                                        <a class="mmba-btn mmba-btn-danger mmba-btn-sm" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mmba_delete_movie&movie_id=' . rawurlencode($movie['id'])), 'mmba_delete_movie')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this movie?', 'movie-meta-by-aris')); ?>');">
                                                            <?php esc_html_e('Delete', 'movie-meta-by-aris'); ?>
                                                        </a>
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
                                <h2><?php esc_html_e('Top 10', 'movie-meta-by-aris'); ?></h2>
                            </div>
                            <?php if (empty($top_movies)) : ?>
                                <p class="mmba-help"><?php esc_html_e('No movies in the catalog yet.', 'movie-meta-by-aris'); ?></p>
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
                                <p class="mmba-help"><?php esc_html_e('Live watch counts. Open a movie, then refresh this page.', 'movie-meta-by-aris'); ?></p>
                            <?php endif; ?>
                        </section>

                        <section class="mmba-card mmba-card-compact">
                            <div class="mmba-card-head mmba-card-head-tight">
                                <h2><?php esc_html_e('Shortcode', 'movie-meta-by-aris'); ?></h2>
                            </div>
                            <div class="mmba-shortcode-stack">
                                <div class="mmba-shortcode-item">
                                    <span><?php esc_html_e('All movies', 'movie-meta-by-aris'); ?></span>
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
                            </div>
                        </section>

                        <section class="mmba-card mmba-card-compact">
                            <div class="mmba-card-head mmba-card-head-tight">
                                <h2><?php esc_html_e('API', 'movie-meta-by-aris'); ?></h2>
                            </div>
                            <dl class="mmba-meta">
                                <div class="mmba-meta-row">
                                    <dt><?php esc_html_e('Titles', 'movie-meta-by-aris'); ?></dt>
                                    <dd><?php echo esc_html((string) $movie_count); ?></dd>
                                </div>
                            </dl>
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

                        <section class="mmba-card mmba-card-compact">
                            <div class="mmba-card-head mmba-card-head-tight">
                                <h2><?php esc_html_e('Backup', 'movie-meta-by-aris'); ?></h2>
                            </div>
                            <div class="mmba-stack">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="mmba_export_json">
                                    <?php wp_nonce_field('mmba_export_json'); ?>
                                    <button type="submit" class="mmba-btn mmba-btn-secondary mmba-btn-block">
                                        <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                        <?php esc_html_e('Export JSON', 'movie-meta-by-aris'); ?>
                                    </button>
                                </form>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="mmba_import_json">
                                    <?php wp_nonce_field('mmba_import_json'); ?>
                                    <div class="mmba-field">
                                        <label for="mmba-import-file"><?php esc_html_e('Import file', 'movie-meta-by-aris'); ?></label>
                                        <input type="file" id="mmba-import-file" name="import_file" accept=".json,application/json" required>
                                    </div>
                                    <div class="mmba-choice-list">
                                        <label class="mmba-choice">
                                            <input type="radio" name="import_mode" value="merge" checked>
                                            <span><?php esc_html_e('Merge matching IDs', 'movie-meta-by-aris'); ?></span>
                                        </label>
                                        <label class="mmba-choice">
                                            <input type="radio" name="import_mode" value="replace">
                                            <span><?php esc_html_e('Replace all movies', 'movie-meta-by-aris'); ?></span>
                                        </label>
                                    </div>
                                    <button type="submit" class="mmba-btn mmba-btn-secondary mmba-btn-block" onclick="return confirm('<?php echo esc_js(__('Import movies from this JSON file?', 'movie-meta-by-aris')); ?>');">
                                        <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                                        <?php esc_html_e('Import JSON', 'movie-meta-by-aris'); ?>
                                    </button>
                                </form>
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
