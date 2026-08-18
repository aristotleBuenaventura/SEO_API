<?php

if (!defined('ABSPATH')) {
    exit;
}

class MMBA_Storage {

    private static $counted_ids = [];

    const OPTION_KEY = 'mmba_movies';
    const VIEWS_KEY = 'mmba_movie_views';
    const JSON_FILENAME = 'movies.json';

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'track_watch_query'], 20);
        add_action('wp_footer', [__CLASS__, 'print_view_tracker'], 99);
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

    /**
     * Count a watch view from ?id= even if a page-cache skipped snippet PHP.
     */
    public static function track_watch_query() {
        if (is_admin() || wp_doing_ajax() || is_feed()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        if (empty($_GET['id'])) {
            return;
        }

        self::increment_view(sanitize_text_field(wp_unslash((string) $_GET['id'])));
    }

    /**
     * Beacon from the plugin so plays are counted even when the watch snippet is outdated.
     */
    public static function print_view_tracker() {
        if (is_admin() || is_feed()) {
            return;
        }

        $base = rest_url('movie-meta/v1/movies/');
        $nonce = wp_create_nonce('wp_rest');
        $current = isset($_GET['id']) ? sanitize_text_field(wp_unslash((string) $_GET['id'])) : '';
        if ($current === '') {
            return;
        }
        ?>
<script>
(function () {
  var base = <?php echo wp_json_encode($base); ?>;
  var nonce = <?php echo wp_json_encode($nonce); ?>;
  var current = <?php echo wp_json_encode($current); ?>;
  if (!current || !base) return;
  fetch(base + encodeURIComponent(current) + '/view', {
    method: 'POST',
    credentials: 'same-origin',
    keepalive: true,
    headers: {
      Accept: 'application/json',
      'X-WP-Nonce': nonce
    }
  }).catch(function () {});
})();
</script>
        <?php
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

        $views = self::get_views();
        unset($views[(string) $id]);
        self::save_views($views);

        return true;
    }

    public static function get_views() {
        $views = get_option(self::VIEWS_KEY, []);
        if (!is_array($views)) {
            return [];
        }

        $clean = [];
        foreach ($views as $id => $count) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            $clean[$id] = max(0, (int) $count);
        }

        return $clean;
    }

    public static function get_view_count($id) {
        $views = self::get_views();
        $id = (string) $id;
        return isset($views[$id]) ? (int) $views[$id] : 0;
    }

    public static function save_views(array $views) {
        $exists = get_option(self::VIEWS_KEY, null);
        if ($exists === null) {
            add_option(self::VIEWS_KEY, $views, '', 'no');
        } else {
            update_option(self::VIEWS_KEY, $views, false);
        }
    }

    /**
     * Count a unique watch-page view. Dedupes by IP for 2 minutes.
     *
     * @return bool True when the count increased.
     */
    public static function increment_view($id) {
        $id = sanitize_text_field((string) $id);
        if ($id === '' || isset(self::$counted_ids[$id])) {
            return false;
        }
        self::$counted_ids[$id] = true;

        if (!self::get_movie($id) || self::is_prefetch_request()) {
            return false;
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ($ua !== '' && preg_match('/bot|crawl|spider|slurp|facebookexternalhit/i', $ua)) {
            return false;
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $lock = 'mmba_vlock_' . md5($id . '|' . $ip);
        if (get_transient($lock)) {
            return false;
        }
        set_transient($lock, 1, 2 * MINUTE_IN_SECONDS);

        $views = self::get_views();
        $views[$id] = (isset($views[$id]) ? (int) $views[$id] : 0) + 1;
        self::save_views($views);

        return true;
    }

    private static function is_prefetch_request() {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method === 'HEAD') {
            return true;
        }

        $hints = strtolower(implode(' ', [
            isset($_SERVER['HTTP_SEC_PURPOSE']) ? (string) $_SERVER['HTTP_SEC_PURPOSE'] : '',
            isset($_SERVER['HTTP_PURPOSE']) ? (string) $_SERVER['HTTP_PURPOSE'] : '',
            isset($_SERVER['HTTP_X_PURPOSE']) ? (string) $_SERVER['HTTP_X_PURPOSE'] : '',
            isset($_SERVER['HTTP_X_MOZ']) ? (string) $_SERVER['HTTP_X_MOZ'] : '',
        ]));

        return strpos($hints, 'prefetch') !== false || strpos($hints, 'prerender') !== false;
    }

    /**
     * Movies ranked by watch views, then recency.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_top_movies($limit = 10) {
        $limit = max(1, min(50, (int) $limit));
        $movies = self::get_movies();
        $views = self::get_views();

        usort($movies, static function ($a, $b) use ($views) {
            $aid = isset($a['id']) ? (string) $a['id'] : '';
            $bid = isset($b['id']) ? (string) $b['id'] : '';
            $va = isset($views[$aid]) ? (int) $views[$aid] : 0;
            $vb = isset($views[$bid]) ? (int) $views[$bid] : 0;
            if ($va !== $vb) {
                return $vb - $va;
            }
            $ta = isset($a['created_at']) ? (string) $a['created_at'] : '';
            $tb = isset($b['created_at']) ? (string) $b['created_at'] : '';
            return strcmp($tb, $ta);
        });

        $top = array_slice(array_values($movies), 0, $limit);
        foreach ($top as &$movie) {
            $mid = isset($movie['id']) ? (string) $movie['id'] : '';
            $movie['views'] = isset($views[$mid]) ? (int) $views[$mid] : 0;
        }
        unset($movie);

        return $top;
    }

    /**
     * Newest movies by created_at, then updated_at.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent_movies($limit = 10) {
        $limit = max(1, min(50, (int) $limit));
        $movies = self::get_movies();

        usort($movies, static function ($a, $b) {
            $ta = isset($a['created_at']) ? (string) $a['created_at'] : '';
            $tb = isset($b['created_at']) ? (string) $b['created_at'] : '';
            if ($ta !== $tb) {
                return strcmp($tb, $ta);
            }
            $ua = isset($a['updated_at']) ? (string) $a['updated_at'] : '';
            $ub = isset($b['updated_at']) ? (string) $b['updated_at'] : '';
            return strcmp($ub, $ua);
        });

        return array_slice(array_values($movies), 0, $limit);
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
