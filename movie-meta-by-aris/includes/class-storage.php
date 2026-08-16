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

    public static function genre_matches($movie_genre, $filter) {
        $filter = strtolower(trim((string) $filter));
        if ($filter === '') {
            return true;
        }

        $parts = preg_split('/\s*,\s*/', (string) $movie_genre);
        if (!is_array($parts)) {
            return strcasecmp((string) $movie_genre, $filter) === 0;
        }

        foreach ($parts as $part) {
            if (strcasecmp(trim((string) $part), $filter) === 0) {
                return true;
            }
        }

        return false;
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

        // Reject obvious junk, but allow ":" in path segments and "#" stream ids
        // (e.g. https://imperial.p2pstream.vip/#owgg9h, ployan.me/watch/?v11#...).
        if (preg_match('#[\s<>"\']#', $raw)) {
            return '';
        }

        return $raw;
    }

    /**
     * Detect how the frontend should render a movie link.
     *
     * @return string 'hls' | 'embed'
     */
    public static function get_movie_link_type($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return 'hls';
        }

        if (preg_match('/\.m3u8(\?|#|$)/i', $url)) {
            return 'hls';
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (is_string($host) && preg_match('/(^|\.)(ployan\.me|morencius\.com|p2pstream\.vip|xtremestream\.xyz)$/i', $host)) {
            return 'embed';
        }

        // Hash-only player ids on known stream hosts (already covered above),
        // plus generic /watch|/embed paths.
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (is_string($path) && preg_match('#/(watch|embed|player|file|download|e)(/|$)#i', $path)) {
            return 'embed';
        }

        // Any https URL that relies on a #stream-id fragment is almost always an embed player.
        if (preg_match('/^https?:\/\/[^#\s]+#[A-Za-z0-9_-]{4,}$/i', $url)) {
            return 'embed';
        }

        return 'hls';
    }

    /**
     * Convert watch/file pages into iframe-friendly embed URLs.
     * e.g. https://morencius.com/file/abc → https://morencius.com/embed/abc
     * Keeps p2pstream hash ids: https://imperial.p2pstream.vip/#owgg9h
     */
    public static function get_embed_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $fragment = isset($parts['fragment']) ? (string) $parts['fragment'] : '';
        $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'https';

        // Morencius / Earnvids-style hosts: /file|/download|/embed/{id}
        if (preg_match('/(^|\.)morencius\.com$/i', $host)
            && preg_match('#^/(file|download|embed)/([a-zA-Z0-9_-]+)/?$#', $path, $m)
        ) {
            return $scheme . '://' . $parts['host'] . '/embed/' . $m[2];
        }

        // p2pstream: keep hash player id on the host root (required for playback).
        if (preg_match('/(^|\.)p2pstream\.vip$/i', $host) && $fragment !== '') {
            return $scheme . '://' . $parts['host'] . '/#' . $fragment;
        }

        return $url;
    }

    /**
     * Escape a playable URL for HTML attributes without dropping #fragments.
     * WordPress esc_url() strips fragments, which breaks p2pstream / ployan players.
     *
     * @param string $url
     * @return string
     */
    public static function escape_play_url($url) {
        $original = trim((string) $url);
        if ($original === '') {
            return '';
        }

        $fragment = '';
        $base = $original;
        if (preg_match('/#([\s\S]*)$/', $original, $m)) {
            $fragment = (string) $m[1];
            $base = substr($original, 0, -strlen($m[0]));
        }

        $escaped = esc_url($base);
        if ($escaped === '') {
            // Already sanitized https stream URLs may still fail esc_url in edge cases.
            if (!preg_match('#^https?://#i', $original)) {
                return '';
            }
            return esc_attr($original);
        }

        if ($fragment === '') {
            return esc_attr($escaped);
        }

        // Keep stream-token characters used by p2pstream / ployan hash players.
        $fragment = preg_replace('/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?%]/', '', $fragment);
        return esc_attr($escaped . '#' . $fragment);
    }

    /**
     * Optional poster thumbnail for known hosts.
     */
    public static function get_poster_url($url) {
        $url = trim((string) $url);
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? (string) $parts['path'] : '';

        if (preg_match('/(^|\.)morencius\.com$/i', $host)
            && preg_match('#^/(file|download|embed)/([a-zA-Z0-9_-]+)/?$#', $path, $m)
        ) {
            return 'https://pixibay.cc/' . $m[2] . '_t.jpg';
        }

        return '';
    }

    public static function sanitize_movie($input, $existing_id = null) {
        $title = isset($input['title']) ? sanitize_text_field(wp_unslash($input['title'])) : '';
        $details = isset($input['details']) ? sanitize_textarea_field(wp_unslash($input['details'])) : '';
        $cast = isset($input['cast']) ? sanitize_text_field(wp_unslash($input['cast'])) : '';
        $year = isset($input['year']) ? sanitize_text_field(wp_unslash($input['year'])) : '';
        $genre = isset($input['genre']) ? sanitize_text_field(wp_unslash($input['genre'])) : '';

        if ($year !== '' && !preg_match('/^\d{4}(\s*[-\/\x{2013}\x{2014}]\s*\d{2,4})?$/u', $year)) {
            $year = preg_replace('/[^\d\-\/\x{2013}\x{2014}\s]/u', '', $year);
            $year = trim(preg_replace('/\s+/', ' ', (string) $year));
        }

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
            'cast'       => $cast,
            'year'       => $year,
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
            'cast'       => isset($movie['cast']) ? (string) $movie['cast'] : '',
            'year'       => isset($movie['year']) ? (string) $movie['year'] : '',
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
