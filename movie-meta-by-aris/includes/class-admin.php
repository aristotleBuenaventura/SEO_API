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
    }

    public static function register_menu() {
        add_menu_page(
            __('Movie Meta by Aris', 'movie-meta-by-aris'),
            __('Movie Meta by Aris', 'movie-meta-by-aris'),
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
        $edit_id = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : '';
        $editing = $edit_id !== '' ? MMBA_Storage::get_movie($edit_id) : null;

        $form = [
            'id'         => $editing ? $editing['id'] : '',
            'title'      => $editing ? $editing['title'] : '',
            'details'    => $editing ? $editing['details'] : '',
            'movie_link' => $editing ? $editing['movie_link'] : '',
            'genre'      => $editing ? $editing['genre'] : '',
        ];

        $rest_url = rest_url(MMBA_API::REST_NS . '/movies');
        $json_url = MMBA_Storage::json_file_url();
        ?>
        <div class="wrap mmba-wrap">
            <h1><?php echo esc_html__('Movie Meta by Aris', 'movie-meta-by-aris'); ?></h1>

            <?php if (!empty($_GET['mmba_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Movie saved successfully.', 'movie-meta-by-aris'); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($_GET['mmba_deleted'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Movie deleted.', 'movie-meta-by-aris'); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($_GET['mmba_imported'])) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: imported count, 2: mode */
                            __('Imported %1$d movie(s) (%2$s).', 'movie-meta-by-aris'),
                            (int) $_GET['mmba_imported'],
                            sanitize_key(wp_unslash($_GET['mmba_mode'] ?? 'merge'))
                        )
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if (!empty($_GET['mmba_error'])) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html(rawurldecode(wp_unslash($_GET['mmba_error']))); ?></p></div>
            <?php endif; ?>

            <div class="mmba-endpoints mmba-card">
                <h2><?php echo esc_html__('Frontend JSON endpoints', 'movie-meta-by-aris'); ?></h2>
                <p><?php echo esc_html__('Pull movie data from either of these URLs:', 'movie-meta-by-aris'); ?></p>
                <p>
                    <strong><?php echo esc_html__('REST API:', 'movie-meta-by-aris'); ?></strong><br>
                    <code class="mmba-copy" data-copy="<?php echo esc_attr($rest_url); ?>"><?php echo esc_html($rest_url); ?></code>
                    <button type="button" class="button button-small mmba-copy-btn" data-target="<?php echo esc_attr($rest_url); ?>"><?php echo esc_html__('Copy', 'movie-meta-by-aris'); ?></button>
                </p>
                <?php if ($json_url !== '') : ?>
                <p>
                    <strong><?php echo esc_html__('Static JSON file:', 'movie-meta-by-aris'); ?></strong><br>
                    <code class="mmba-copy" data-copy="<?php echo esc_attr($json_url); ?>"><?php echo esc_html($json_url); ?></code>
                    <button type="button" class="button button-small mmba-copy-btn" data-target="<?php echo esc_attr($json_url); ?>"><?php echo esc_html__('Copy', 'movie-meta-by-aris'); ?></button>
                </p>
                <?php endif; ?>
                <p class="description"><?php echo esc_html__('Optional filter: append ?genre=Action to the REST URL.', 'movie-meta-by-aris'); ?></p>
            </div>

            <div class="mmba-card">
                <h2><?php echo esc_html__('Export / Import JSON', 'movie-meta-by-aris'); ?></h2>
                <div class="mmba-io-grid">
                    <div class="mmba-io-block">
                        <h3><?php echo esc_html__('Export', 'movie-meta-by-aris'); ?></h3>
                        <p><?php echo esc_html__('Download all movies as a JSON file.', 'movie-meta-by-aris'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="mmba_export_json">
                            <?php wp_nonce_field('mmba_export_json'); ?>
                            <button type="submit" class="button button-secondary"><?php echo esc_html__('Export JSON', 'movie-meta-by-aris'); ?></button>
                        </form>
                    </div>
                    <div class="mmba-io-block">
                        <h3><?php echo esc_html__('Import', 'movie-meta-by-aris'); ?></h3>
                        <p><?php echo esc_html__('Upload a JSON file exported from this plugin (or any file with a movies array).', 'movie-meta-by-aris'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="mmba_import_json">
                            <?php wp_nonce_field('mmba_import_json'); ?>
                            <p>
                                <input type="file" name="import_file" accept=".json,application/json" required>
                            </p>
                            <p>
                                <label>
                                    <input type="radio" name="import_mode" value="merge" checked>
                                    <?php echo esc_html__('Merge (keep existing, update matching IDs)', 'movie-meta-by-aris'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="import_mode" value="replace">
                                    <?php echo esc_html__('Replace all movies', 'movie-meta-by-aris'); ?>
                                </label>
                            </p>
                            <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Import movies from this JSON file?', 'movie-meta-by-aris')); ?>');">
                                <?php echo esc_html__('Import JSON', 'movie-meta-by-aris'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="mmba-card">
                <h2><?php echo esc_html__('Shortcode', 'movie-meta-by-aris'); ?></h2>
                <p><?php echo esc_html__('Paste any of these into a page or post:', 'movie-meta-by-aris'); ?></p>
                <ul class="mmba-shortcode-list">
                    <li><code>[movie_meta]</code> — <?php echo esc_html__('all movies with details + player', 'movie-meta-by-aris'); ?></li>
                    <li><code>[movie_meta id="MOVIE_ID"]</code> — <?php echo esc_html__('one movie', 'movie-meta-by-aris'); ?></li>
                    <li><code>[movie_meta genre="Action"]</code> — <?php echo esc_html__('filter by genre', 'movie-meta-by-aris'); ?></li>
                    <li><code>[movie_meta layout="grid" limit="6"]</code> — <?php echo esc_html__('grid layout, max 6', 'movie-meta-by-aris'); ?></li>
                    <li><code>[movie_meta player="0"]</code> — <?php echo esc_html__('details only, no video player', 'movie-meta-by-aris'); ?></li>
                </ul>
            </div>

            <div class="mmba-card">
                <h2><?php echo $editing ? esc_html__('Edit movie', 'movie-meta-by-aris') : esc_html__('Add movie', 'movie-meta-by-aris'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mmba-form">
                    <input type="hidden" name="action" value="mmba_save_movie">
                    <?php wp_nonce_field('mmba_save_movie'); ?>
                    <?php if ($form['id'] !== '') : ?>
                        <input type="hidden" name="movie_id" value="<?php echo esc_attr($form['id']); ?>">
                    <?php endif; ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="mmba-title"><?php echo esc_html__('Title', 'movie-meta-by-aris'); ?></label></th>
                            <td><input type="text" class="regular-text" id="mmba-title" name="title" value="<?php echo esc_attr($form['title']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mmba-genre"><?php echo esc_html__('Genre', 'movie-meta-by-aris'); ?></label></th>
                            <td><input type="text" class="regular-text" id="mmba-genre" name="genre" value="<?php echo esc_attr($form['genre']); ?>" placeholder="Action, Drama, Comedy..."></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mmba-movie-link"><?php echo esc_html__('Movie link', 'movie-meta-by-aris'); ?></label></th>
                            <td>
                                <input type="text" class="large-text" id="mmba-movie-link" name="movie_link" value="<?php echo esc_attr($form['movie_link']); ?>" placeholder="https://stream.ebsbd.com/.../playlist.m3u8" required>
                                <p class="description"><?php echo esc_html__('HLS / m3u8 stream URL, e.g. https://stream.ebsbd.com/.../playlist.m3u8', 'movie-meta-by-aris'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mmba-details"><?php echo esc_html__('Details', 'movie-meta-by-aris'); ?></label></th>
                            <td><textarea class="large-text" rows="5" id="mmba-details" name="details"><?php echo esc_textarea($form['details']); ?></textarea></td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php echo $editing ? esc_html__('Update movie', 'movie-meta-by-aris') : esc_html__('Add movie', 'movie-meta-by-aris'); ?>
                        </button>
                        <?php if ($editing) : ?>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">
                                <?php echo esc_html__('Cancel', 'movie-meta-by-aris'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <div class="mmba-card mmba-list">
                <h2><?php echo esc_html__('Saved movies', 'movie-meta-by-aris'); ?> <span class="mmba-count">(<?php echo esc_html((string) count($movies)); ?>)</span></h2>

                <?php if (empty($movies)) : ?>
                    <p><?php echo esc_html__('No movies yet. Add one above.', 'movie-meta-by-aris'); ?></p>
                <?php else : ?>
                    <div class="mmba-table-wrap">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Title', 'movie-meta-by-aris'); ?></th>
                                    <th><?php echo esc_html__('Genre', 'movie-meta-by-aris'); ?></th>
                                    <th><?php echo esc_html__('Shortcode', 'movie-meta-by-aris'); ?></th>
                                    <th><?php echo esc_html__('Movie link', 'movie-meta-by-aris'); ?></th>
                                    <th><?php echo esc_html__('Details', 'movie-meta-by-aris'); ?></th>
                                    <th><?php echo esc_html__('Actions', 'movie-meta-by-aris'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movies as $movie) : ?>
                                    <?php $movie_shortcode = '[movie_meta id="' . $movie['id'] . '"]'; ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($movie['title']); ?></strong></td>
                                        <td><?php echo esc_html($movie['genre']); ?></td>
                                        <td>
                                            <code><?php echo esc_html($movie_shortcode); ?></code>
                                            <button type="button" class="button button-small mmba-copy-btn" data-target="<?php echo esc_attr($movie_shortcode); ?>"><?php echo esc_html__('Copy', 'movie-meta-by-aris'); ?></button>
                                        </td>
                                        <td><code class="mmba-link"><?php echo esc_html($movie['movie_link']); ?></code></td>
                                        <td><?php echo esc_html(wp_trim_words($movie['details'], 18)); ?></td>
                                        <td class="mmba-actions">
                                            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&edit=' . rawurlencode($movie['id']))); ?>">
                                                <?php echo esc_html__('Edit', 'movie-meta-by-aris'); ?>
                                            </a>
                                            <a class="button button-small button-link-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mmba_delete_movie&movie_id=' . rawurlencode($movie['id'])), 'mmba_delete_movie')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this movie?', 'movie-meta-by-aris')); ?>');">
                                                <?php echo esc_html__('Delete', 'movie-meta-by-aris'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
