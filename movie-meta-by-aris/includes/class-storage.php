<?php

if (!defined('ABSPATH')) {
    exit;
}

class MMBA_Storage {

    const OPTION_KEY = 'mmba_movies';
    const JSON_FILENAME = 'movies.json';

    public static function init() {
        // Intentionally empty: never write files on frontend page loads.
    }

    public static function activate() {
        $movies = get_option(self::OPTION_KEY, null);
        if ($movies === null) {
            add_option(self::OPTION_KEY, [], '', 'no');
            $movies = [];
        }

        self::sync_json_file(is_array($movies) ? $movies : []);
    }

    public static function data_dir() {
        $upload = wp_upload_dir(null, false);
        if (!empty($upload['error'])) {
            return '';
        }
        return trailingslashit($upload['basedir']) . 'movie-meta-by-aris';
    }

    public static function data_url() {
        $upload = wp_upload_dir(null, false);
        if (!empty($upload['error'])) {
            return '';
        }
        return trailingslashit($upload['baseurl']) . 'movie-meta-by-aris';
    }

    public static function json_file_path() {
        $dir = self::data_dir();
        return $dir === '' ? '' : trailingslashit($dir) . self::JSON_FILENAME;
    }

    public static function json_file_url() {
        $url = self::data_url();
        return $url === '' ? '' : trailingslashit($url) . self::JSON_FILENAME;
    }

    public static function ensure_data_dir() {
        $dir = self::data_dir();
        if ($dir === '') {
            return false;
        }

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $index = trailingslashit($dir) . 'index.php';
        if (!file_exists($index)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        return true;
    }

    public static function get_movies() {
        $movies = get_option(self::OPTION_KEY, []);
        if (!is_array($movies)) {
            return [];
        }

        $clean = [];
        foreach ($movies as $movie) {
            if (!is_array($movie) || empty($movie['id'])) {
                continue;
            }
            $clean[] = self::normalize_movie($movie);
        }

        return array_values($clean);
    }

    public static function get_movie($id) {
        $id = (string) $id;
        foreach (self::get_movies() as $movie) {
            if ((string) $movie['id'] === $id) {
                return $movie;
            }
        }
        return null;
    }

    public static function save_movies(array $movies) {
        $movies = array_values($movies);

        // Keep option out of autoload to avoid bloating every request.
        $exists = get_option(self::OPTION_KEY, null);
        if ($exists === null) {
            add_option(self::OPTION_KEY, $movies, '', 'no');
        } else {
            update_option(self::OPTION_KEY, $movies, false);
        }

        self::sync_json_file($movies);
        return $movies;
    }

    public static function sync_json_file(array $movies) {
        if (!self::ensure_data_dir()) {
            return false;
        }

        $path = self::json_file_path();
        if ($path === '') {
            return false;
        }

        $payload = [
            'generated_at' => gmdate('c'),
            'count'        => count($movies),
            'movies'       => array_values($movies),
        ];

        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        // Soft-fail: never break admin/frontend if disk write fails.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return false !== @file_put_contents($path, $json . "\n", LOCK_EX);
    }

    public static function export_payload() {
        $movies = self::get_movies();
        return [
            'plugin'       => 'movie-meta-by-aris',
            'version'      => defined('MMBA_VERSION') ? MMBA_VERSION : '1.0.0',
            'generated_at' => gmdate('c'),
            'count'        => count($movies),
            'movies'       => $movies,
        ];
    }

    public static function import_from_data($data, $mode = 'merge') {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (!is_array($data)) {
            return new WP_Error('mmba_invalid_json', __('Invalid JSON file.', 'movie-meta-by-aris'));
        }

        $incoming = [];
        if (isset($data['movies']) && is_array($data['movies'])) {
            $incoming = $data['movies'];
        } elseif (self::is_list_array($data)) {
            $incoming = $data;
        } else {
            return new WP_Error('mmba_invalid_json', __('JSON must contain a movies array.', 'movie-meta-by-aris'));
        }

        $imported = [];
        foreach ($incoming as $row) {
            if (!is_array($row)) {
                continue;
            }

            $existing_id = !empty($row['id']) ? sanitize_text_field((string) $row['id']) : null;
            $movie = self::sanitize_movie($row, $existing_id);
            if (is_wp_error($movie)) {
                continue;
            }
            $imported[] = $movie;
        }

        if (empty($imported)) {
            return new WP_Error('mmba_empty_import', __('No valid movies found in the JSON file.', 'movie-meta-by-aris'));
        }

        if ($mode === 'replace') {
            self::save_movies($imported);
            return [
                'mode'   => 'replace',
                'count'  => count($imported),
                'movies' => $imported,
            ];
        }

        // Merge by id (incoming wins), keep existing movies not in import.
        $by_id = [];
        foreach (self::get_movies() as $movie) {
            $by_id[(string) $movie['id']] = $movie;
        }
        foreach ($imported as $movie) {
            $by_id[(string) $movie['id']] = $movie;
        }

        $merged = array_values($by_id);
        self::save_movies($merged);

        return [
            'mode'   => 'merge',
            'count'  => count($imported),
            'total'  => count($merged),
            'movies' => $merged,
        ];
    }

    public static function sanitize_stream_url($raw) {
        $raw = trim(wp_unslash((string) $raw));
        $raw = str_replace(["\r", "\n", "\0"], '', $raw);

        if ($raw === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $raw)) {
            return '';
        }

        // Reject obvious junk, but allow ":" in path segments.
        if (preg_match('#[\s<>"\']#', $raw)) {
            return '';
        }

        return $raw;
    }

    public static function sanitize_movie($input, $existing_id = null) {
        $title = isset($input['title']) ? sanitize_text_field(wp_unslash($input['title'])) : '';
        $details = isset($input['details']) ? sanitize_textarea_field(wp_unslash($input['details'])) : '';
        $genre = isset($input['genre']) ? sanitize_text_field(wp_unslash($input['genre'])) : '';

        $raw_link = isset($input['movie_link']) ? $input['movie_link'] : '';
        $movie_link = self::sanitize_stream_url($raw_link);
        if ($movie_link === '') {
            // Last resort: try WP sanitizer.
            $movie_link = esc_url_raw(wp_unslash((string) $raw_link), ['http', 'https']);
        }

        if ($title === '') {
            return new WP_Error('mmba_missing_title', __('Title is required.', 'movie-meta-by-aris'));
        }

        if ($movie_link === '') {
            return new WP_Error('mmba_missing_link', __('Movie link is required.', 'movie-meta-by-aris'));
        }

        $id = $existing_id ? sanitize_text_field((string) $existing_id) : self::generate_id();
        if ($id === '') {
            $id = self::generate_id();
        }

        $now = gmdate('c');
        $existing = $existing_id ? self::get_movie($existing_id) : null;

        return [
            'id'         => $id,
            'title'      => $title,
            'details'    => $details,
            'movie_link' => $movie_link,
            'genre'      => $genre,
            'created_at' => $existing && !empty($existing['created_at']) ? $existing['created_at'] : $now,
            'updated_at' => $now,
        ];
    }

    public static function add_movie($input) {
        $movie = self::sanitize_movie($input);
        if (is_wp_error($movie)) {
            return $movie;
        }

        $movies = self::get_movies();
        array_unshift($movies, $movie);
        self::save_movies($movies);

        return $movie;
    }

    public static function update_movie($id, $input) {
        $movies = self::get_movies();
        $found = false;

        foreach ($movies as $index => $existing) {
            if ((string) $existing['id'] !== (string) $id) {
                continue;
            }

            $movie = self::sanitize_movie($input, $id);
            if (is_wp_error($movie)) {
                return $movie;
            }

            $movies[$index] = $movie;
            $found = true;
            break;
        }

        if (!$found) {
            return new WP_Error('mmba_not_found', __('Movie not found.', 'movie-meta-by-aris'));
        }

        self::save_movies($movies);
        return self::get_movie($id);
    }

    public static function delete_movie($id) {
        $movies = self::get_movies();
        $filtered = array_values(array_filter($movies, static function ($movie) use ($id) {
            return (string) $movie['id'] !== (string) $id;
        }));

        if (count($filtered) === count($movies)) {
            return new WP_Error('mmba_not_found', __('Movie not found.', 'movie-meta-by-aris'));
        }

        self::save_movies($filtered);
        return true;
    }

    private static function normalize_movie(array $movie) {
        return [
            'id'         => isset($movie['id']) ? (string) $movie['id'] : '',
            'title'      => isset($movie['title']) ? (string) $movie['title'] : '',
            'details'    => isset($movie['details']) ? (string) $movie['details'] : '',
            'movie_link' => isset($movie['movie_link']) ? (string) $movie['movie_link'] : '',
            'genre'      => isset($movie['genre']) ? (string) $movie['genre'] : '',
            'created_at' => isset($movie['created_at']) ? (string) $movie['created_at'] : '',
            'updated_at' => isset($movie['updated_at']) ? (string) $movie['updated_at'] : '',
        ];
    }

    private static function is_list_array(array $arr) {
        if (function_exists('array_is_list')) {
            return array_is_list($arr);
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private static function generate_id() {
        try {
            return bin2hex(random_bytes(8));
        } catch (Exception $e) {
            return uniqid('mmba_', true);
        }
    }
}
