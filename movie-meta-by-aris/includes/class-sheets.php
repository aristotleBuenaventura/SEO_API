<?php

if (!defined('ABSPATH')) {
    exit;
}

class MMBA_Sheets {

    const SPREADSHEET_ID = '1g5I-9IPvlWQe72jkDYe4T-UNWWy5XLfEeoDAjHw28B8';
    const RANGE = 'A1:J5000';
    const TOP_SEARCHES_SHEET = 'Top Searches';
    const TOP_SEARCHES_LIMIT = 100;
    const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SERVICE_EMAIL = 'movie-660@fifth-branch-506015-f9.iam.gserviceaccount.com';

    const DATA_KEY = 'mmba_sheet_data';
    const ERROR_KEY = 'mmba_sheet_error';
    const FRESH_KEY = 'mmba_sheet_fresh';
    const TOKEN_KEY = 'mmba_gs_token';
    const CACHE_TTL = 600;
    const TOP_SEARCHES_ERROR_KEY = 'mmba_top_searches_error';
    const TOP_SEARCHES_SYNCED_KEY = 'mmba_top_searches_synced_at';
    const TOP_SEARCHES_DIRTY_KEY = 'mmba_top_searches_dirty';
    const TOP_SEARCHES_SYNC_LOCK = 'mmba_top_searches_sync_lock';

    /**
     * Production hosts allowed to push top searches to Google Sheets.
     *
     * @return array<int, string>
     */
    public static function top_searches_write_hosts() {
        $hosts = [
            'desimovieshub.com',
            'www.desimovieshub.com',
        ];

        /**
         * Filter which site hosts may write top searches to Google Sheets.
         *
         * @param array<int, string> $hosts
         */
        return apply_filters('mmba_top_searches_write_hosts', $hosts);
    }

    /**
     * Only production may write top searches (staging/local must not overwrite the sheet).
     */
    public static function can_sync_top_searches_to_sheet() {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);
        $allowed = array_map('strtolower', self::top_searches_write_hosts());

        return in_array($host, $allowed, true);
    }

    public static function spreadsheet_id() {
        return self::SPREADSHEET_ID;
    }

    public static function spreadsheet_url() {
        return 'https://docs.google.com/spreadsheets/d/' . self::SPREADSHEET_ID . '/edit';
    }

    public static function service_email() {
        return self::SERVICE_EMAIL;
    }

    public static function credentials_path() {
        $php = MMBA_PLUGIN_DIR . 'credentials/google-service-account.php';
        if (is_readable($php)) {
            return $php;
        }
        return MMBA_PLUGIN_DIR . 'credentials/google-service-account.json';
    }

    public static function init() {
        add_action('mmba_sync_sheet', [__CLASS__, 'sync']);
        add_action('mmba_sync_top_searches', [__CLASS__, 'sync_top_searches']);
        add_action('shutdown', [__CLASS__, 'maybe_sync_top_searches_on_shutdown'], 20);
    }

    public static function schedule() {
        if (!wp_next_scheduled('mmba_sync_sheet')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'mmba_sync_sheet');
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled('mmba_sync_sheet');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mmba_sync_sheet');
        }

        $top_timestamp = wp_next_scheduled('mmba_sync_top_searches');
        if ($top_timestamp) {
            wp_unschedule_event($top_timestamp, 'mmba_sync_top_searches');
        }
    }

    /**
     * @return array{catalog: array, by_id: array, series_episodes: array, rows: array, fetched_at: string}
     */
    public static function get_payload($force = false) {
        $cached = get_option(self::DATA_KEY, null);
        $fresh = (bool) get_transient(self::FRESH_KEY);

        if (!$force && $fresh && is_array($cached) && !empty($cached['catalog'])) {
            return self::normalize_payload($cached);
        }

        $synced = self::sync($force);
        if (!is_wp_error($synced)) {
            return $synced;
        }

        if (is_array($cached)) {
            return self::normalize_payload($cached);
        }

        return self::empty_payload();
    }

    /**
     * @return array|WP_Error
     */
    public static function sync($force = false) {
        if (!$force && get_transient(self::FRESH_KEY)) {
            $cached = get_option(self::DATA_KEY, null);
            if (is_array($cached)) {
                return self::normalize_payload($cached);
            }
        }

        $rows = self::fetch_rows();
        if (is_wp_error($rows)) {
            self::save_option(self::ERROR_KEY, $rows->get_error_message());
            return $rows;
        }

        $payload = self::build_payload($rows);
        self::save_option(self::DATA_KEY, $payload);
        self::save_option(self::ERROR_KEY, '');
        set_transient(self::FRESH_KEY, 1, self::CACHE_TTL);

        if (class_exists('MMBA_Storage')) {
            MMBA_Storage::sync_json_file($payload['catalog']);
        }

        self::sync_top_searches();

        return $payload;
    }

    public static function top_searches_last_error() {
        $err = get_option(self::TOP_SEARCHES_ERROR_KEY, '');
        return is_string($err) ? $err : '';
    }

    public static function top_searches_last_synced_at() {
        $at = get_option(self::TOP_SEARCHES_SYNCED_KEY, '');
        return is_string($at) ? $at : '';
    }

    /**
     * Queue an immediate end-of-request sync (plus cron backup).
     */
    public static function queue_top_searches_sync() {
        if (!self::can_sync_top_searches_to_sheet()) {
            return;
        }

        set_transient(self::TOP_SEARCHES_DIRTY_KEY, 1, 15 * MINUTE_IN_SECONDS);

        if (!wp_next_scheduled('mmba_sync_top_searches')) {
            wp_schedule_single_event(time() + 120, 'mmba_sync_top_searches');
        }
    }

    /**
     * Push pending top searches before PHP exits (does not rely on WP-Cron).
     */
    public static function maybe_sync_top_searches_on_shutdown() {
        if (!self::can_sync_top_searches_to_sheet()) {
            return;
        }
        if (!get_transient(self::TOP_SEARCHES_DIRTY_KEY)) {
            return;
        }
        if (get_transient(self::TOP_SEARCHES_SYNC_LOCK)) {
            return;
        }

        set_transient(self::TOP_SEARCHES_SYNC_LOCK, 1, 30);
        delete_transient(self::TOP_SEARCHES_DIRTY_KEY);
        self::sync_top_searches();
    }

    /**
     * Write overall + weekly top searches to the Top Searches sheet.
     *
     * @return true|WP_Error
     */
    public static function sync_top_searches() {
        if (!self::can_sync_top_searches_to_sheet()) {
            return true;
        }

        if (!class_exists('MMBA_Storage') || !method_exists('MMBA_Storage', 'get_top_searches')) {
            return true;
        }

        $overall = MMBA_Storage::get_top_searches(self::TOP_SEARCHES_LIMIT, 'overall');
        $weekly = MMBA_Storage::get_top_searches(self::TOP_SEARCHES_LIMIT, 'weekly');
        if (!is_array($overall)) {
            $overall = [];
        }
        if (!is_array($weekly)) {
            $weekly = [];
        }

        $values = self::build_top_searches_sheet_values($overall, $weekly);
        $range = self::top_searches_range();
        $result = self::write_values($range, $values);

        if (is_wp_error($result)) {
            delete_transient(self::TOKEN_KEY);
            $result = self::write_values($range, $values);
        }

        if (is_wp_error($result)) {
            self::save_option(self::TOP_SEARCHES_ERROR_KEY, $result->get_error_message());
            return $result;
        }

        self::save_option(self::TOP_SEARCHES_ERROR_KEY, '');
        self::save_option(self::TOP_SEARCHES_SYNCED_KEY, gmdate('c'));

        return true;
    }

    /**
     * @param array<int, array{query?: string, count?: int}> $overall
     * @param array<int, array{query?: string, count?: int}> $weekly
     * @return array<int, array{0: string, 1: string}>
     */
    private static function build_top_searches_sheet_values(array $overall, array $weekly) {
        $values = [
            ['Overall Top Searches', 'Weekly Top Searches'],
        ];

        for ($i = 0; $i < self::TOP_SEARCHES_LIMIT; $i++) {
            $values[] = [
                isset($overall[$i]['query']) ? (string) $overall[$i]['query'] : '',
                isset($weekly[$i]['query']) ? (string) $weekly[$i]['query'] : '',
            ];
        }

        return $values;
    }

    private static function top_searches_range() {
        return "'" . self::TOP_SEARCHES_SHEET . "'!A1:B" . (self::TOP_SEARCHES_LIMIT + 1);
    }

    /**
     * @param array<int, array<int, string>> $values
     * @return true|WP_Error
     */
    private static function write_values($range, array $values) {
        $token = self::get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $path = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s',
            self::SPREADSHEET_ID,
            rawurlencode($range)
        );
        $url = add_query_arg(['valueInputOption' => 'RAW'], $path);

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode(['values' => array_values($values)]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = isset($body['error']['message'])
                ? (string) $body['error']['message']
                : __('Google Sheets write failed.', 'movie-meta-by-aris');
            return new WP_Error('mmba_sheets_write', $message);
        }

        return true;
    }

    public static function last_error() {
        $err = get_option(self::ERROR_KEY, '');
        return is_string($err) ? $err : '';
    }

    public static function last_synced_at() {
        $payload = get_option(self::DATA_KEY, []);
        return is_array($payload) && !empty($payload['fetched_at']) ? (string) $payload['fetched_at'] : '';
    }

    /**
     * @return array<int, array<int, string>>|WP_Error
     */
    private static function fetch_rows() {
        $token = self::get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $url = add_query_arg(
            [
                'majorDimension' => 'ROWS',
                'valueRenderOption' => 'UNFORMATTED_VALUE',
            ],
            sprintf(
                'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s',
                rawurlencode(self::SPREADSHEET_ID),
                rawurlencode(self::RANGE)
            )
        );

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $message = isset($body['error']['message']) ? (string) $body['error']['message'] : __('Google Sheets request failed.', 'movie-meta-by-aris');
            return new WP_Error('mmba_sheets_http', $message);
        }

        $values = isset($body['values']) && is_array($body['values']) ? $body['values'] : [];
        return $values;
    }

    /**
     * @param array<int, array<int, mixed>> $values
     */
    private static function build_payload(array $values) {
        $header = [];
        $start = 0;
        if (!empty($values[0]) && is_array($values[0])) {
            foreach ($values[0] as $i => $cell) {
                $header[$i] = strtolower(trim((string) $cell));
            }
            $start = 1;
        }

        $map = [
            'type'    => self::header_index($header, ['type']),
            'title'   => self::header_index($header, ['title']),
            'season'  => self::header_index($header, ['season']),
            'episode' => self::header_index($header, ['episode']),
            'link'    => self::header_index($header, ['link', 'movie link', 'movie_link']),
            'genre'   => self::header_index($header, ['genre']),
            'poster'  => self::header_index($header, ['poster']),
            'detail'  => self::header_index($header, ['detail', 'details']),
            'year'    => self::header_index($header, ['year']),
            'cast'    => self::header_index($header, ['cast']),
        ];

        $playables = [];
        $row_n = 0;
        $total = count($values);
        for ($r = $start; $r < $total; $r++) {
            $line = is_array($values[$r]) ? $values[$r] : [];
            $title = self::cell($line, $map['title']);
            $link = MMBA_Storage::sanitize_stream_url(self::cell($line, $map['link']));
            if ($title === '' || $link === '') {
                continue;
            }

            $type = strtolower(self::cell($line, $map['type']));
            $is_series = ($type === 'series' || $type === 'tv' || $type === 'show');
            $season = self::cell($line, $map['season']);
            $episode = self::cell($line, $map['episode']);
            $row_n++;

            $playables[] = [
                'type'       => $is_series ? 'series' : 'movie',
                'title'      => $title,
                'season'     => $season,
                'episode'    => $episode,
                'season_n'   => self::parse_index($season),
                'episode_n'  => self::parse_index($episode),
                'movie_link' => $link,
                'genre'      => self::cell($line, $map['genre']),
                'poster'     => self::sanitize_poster(self::cell($line, $map['poster'])),
                'details'    => self::cell($line, $map['detail']),
                'year'       => self::cell($line, $map['year']),
                'cast'       => self::cell($line, $map['cast']),
                'sheet_row'  => $r + 1,
                'sort'       => $row_n,
            ];
        }

        return self::assemble($playables);
    }

    /**
     * @param array<int, array<string, mixed>> $playables
     */
    private static function assemble(array $playables) {
        $series_groups = [];
        $movies = [];

        foreach ($playables as $item) {
            if ($item['type'] === 'series') {
                $key = self::series_key($item['title']);
                if (!isset($series_groups[$key])) {
                    $series_groups[$key] = [];
                }
                $series_groups[$key][] = $item;
                continue;
            }
            $movies[] = $item;
        }

        $catalog = [];
        $by_id = [];
        $series_episodes = [];

        foreach ($movies as $item) {
            $id = self::stable_id('movie', $item['title'] . '|' . $item['movie_link']);
            $row = self::catalog_row($id, 'movie', $item, $item['sort']);
            $catalog[] = $row;
            $by_id[$id] = $row;
        }

        foreach ($series_groups as $group) {
            usort($group, static function ($a, $b) {
                if ((int) $a['season_n'] !== (int) $b['season_n']) {
                    return (int) $a['season_n'] - (int) $b['season_n'];
                }
                if ((int) $a['episode_n'] !== (int) $b['episode_n']) {
                    return (int) $a['episode_n'] - (int) $b['episode_n'];
                }
                return (int) $a['sort'] - (int) $b['sort'];
            });

            $first = $group[0];
            $series_id = self::stable_id('series', $first['title']);
            $poster = '';
            foreach ($group as $ep) {
                if (!empty($ep['poster'])) {
                    $poster = $ep['poster'];
                    break;
                }
            }

            $episodes = [];
            foreach ($group as $ep) {
                $eid = self::stable_id('episode', $ep['title'] . '|' . $ep['season'] . '|' . $ep['episode'] . '|' . $ep['movie_link']);
                $episode = [
                    'id'         => $eid,
                    'series_id'  => $series_id,
                    'type'       => 'episode',
                    'title'      => $ep['title'],
                    'season'     => $ep['season'],
                    'episode'    => $ep['episode'],
                    'season_n'   => (int) $ep['season_n'],
                    'episode_n'  => (int) $ep['episode_n'],
                    'movie_link' => $ep['movie_link'],
                    'genre'      => $ep['genre'],
                    'poster'     => $ep['poster'] !== '' ? $ep['poster'] : $poster,
                    'details'    => $ep['details'],
                    'year'       => $ep['year'],
                    'cast'       => $ep['cast'],
                    'created_at' => self::stamp_from_sort((int) $ep['sort']),
                    'updated_at' => self::stamp_from_sort((int) $ep['sort']),
                ];
                $episodes[] = $episode;
                $by_id[$eid] = $episode;
            }

            $series_episodes[$series_id] = $episodes;
            $meta = $first;
            $meta['poster'] = $poster;
            $meta['movie_link'] = $first['movie_link'];
            $row = self::catalog_row($series_id, 'series', $meta, (int) $first['sort']);
            $row['episode_count'] = count($episodes);
            $row['season_count'] = count(array_unique(array_map(static function ($ep) {
                return (string) $ep['season_n'];
            }, $episodes)));
            $catalog[] = $row;
            $by_id[$series_id] = $row;
        }

        usort($catalog, static function ($a, $b) {
            return ((int) ($a['sort'] ?? 0)) - ((int) ($b['sort'] ?? 0));
        });

        foreach ($catalog as &$row) {
            unset($row['sort']);
        }
        unset($row);

        return [
            'catalog'          => array_values($catalog),
            'by_id'            => $by_id,
            'series_episodes'  => $series_episodes,
            'fetched_at'       => gmdate('c'),
            'count'            => count($catalog),
        ];
    }

    private static function catalog_row($id, $type, array $item, $sort) {
        return [
            'id'            => $id,
            'type'          => $type,
            'title'         => $item['title'],
            'details'       => $item['details'],
            'cast'          => $item['cast'],
            'year'          => $item['year'],
            'movie_link'    => $item['movie_link'],
            'genre'         => $item['genre'],
            'poster'        => $item['poster'],
            'season'        => $type === 'series' ? $item['season'] : '',
            'episode'       => $type === 'series' ? $item['episode'] : '',
            'episode_count' => $type === 'series' ? 1 : 0,
            'season_count'  => $type === 'series' ? 1 : 0,
            'created_at'    => self::stamp_from_sort((int) $sort),
            'updated_at'    => self::stamp_from_sort((int) $sort),
            'sort'          => (int) $sort,
        ];
    }

    private static function stamp_from_sort($sort) {
        // Sheet order: first data rows are treated as newest.
        return gmdate('c', 4102444800 - max(1, (int) $sort));
    }

    private static function stable_id($kind, $seed) {
        return substr($kind, 0, 1) . substr(md5(strtolower(trim((string) $seed))), 0, 15);
    }

    private static function series_key($title) {
        return strtolower(preg_replace('/\s+/', ' ', trim((string) $title)));
    }

    private static function parse_index($value) {
        if (preg_match('/(\d+)/', (string) $value, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private static function cell(array $line, $index) {
        if ($index === null || $index === false) {
            return '';
        }
        $index = (int) $index;
        if (!isset($line[$index])) {
            return '';
        }
        return trim((string) $line[$index]);
    }

    private static function header_index(array $header, array $aliases) {
        foreach ($header as $i => $name) {
            if (in_array($name, $aliases, true)) {
                return $i;
            }
        }
        return null;
    }

    private static function sanitize_poster($url) {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return esc_url_raw($url);
    }

    /**
     * @return string|WP_Error
     */
    private static function get_access_token() {
        $cached = get_transient(self::TOKEN_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $creds = self::load_credentials();
        if (is_wp_error($creds)) {
            return $creds;
        }

        $now = time();
        $header = self::b64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64url(wp_json_encode([
            'iss'   => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $unsigned = $header . '.' . $claims;

        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok || $signature === '') {
            return new WP_Error('mmba_jwt', __('Could not sign Google service-account token.', 'movie-meta-by-aris'));
        }

        $jwt = $unsigned . '.' . self::b64url($signature);
        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $token = is_array($body) && !empty($body['access_token']) ? (string) $body['access_token'] : '';
        if ($token === '') {
            $message = isset($body['error_description']) ? (string) $body['error_description'] : __('Google auth failed.', 'movie-meta-by-aris');
            return new WP_Error('mmba_google_auth', $message);
        }

        $ttl = isset($body['expires_in']) ? max(60, ((int) $body['expires_in']) - 60) : 3300;
        set_transient(self::TOKEN_KEY, $token, $ttl);
        return $token;
    }

    /**
     * @return array|WP_Error
     */
    private static function load_credentials() {
        $path = self::credentials_path();
        if (!is_readable($path)) {
            return new WP_Error(
                'mmba_creds_missing',
                sprintf(
                    __('Google credentials file is missing. Upload credentials to the plugin credentials folder and share the sheet with %s.', 'movie-meta-by-aris'),
                    self::SERVICE_EMAIL
                )
            );
        }

        $data = null;
        if (substr($path, -4) === '.php') {
            $data = include $path;
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $raw = file_get_contents($path);
            $data = json_decode((string) $raw, true);
        }
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            return new WP_Error('mmba_creds_invalid', __('Google credentials JSON is invalid.', 'movie-meta-by-aris'));
        }

        return $data;
    }

    private static function b64url($data) {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }

    private static function empty_payload() {
        return [
            'catalog'         => [],
            'by_id'           => [],
            'series_episodes' => [],
            'fetched_at'      => '',
            'count'           => 0,
        ];
    }

    private static function normalize_payload($cached) {
        if (!is_array($cached)) {
            return self::empty_payload();
        }
        return [
            'catalog'         => isset($cached['catalog']) && is_array($cached['catalog']) ? $cached['catalog'] : [],
            'by_id'           => isset($cached['by_id']) && is_array($cached['by_id']) ? $cached['by_id'] : [],
            'series_episodes' => isset($cached['series_episodes']) && is_array($cached['series_episodes']) ? $cached['series_episodes'] : [],
            'fetched_at'      => isset($cached['fetched_at']) ? (string) $cached['fetched_at'] : '',
            'count'           => isset($cached['count']) ? (int) $cached['count'] : 0,
        ];
    }

    private static function save_option($key, $value) {
        $exists = get_option($key, null);
        if ($exists === null) {
            add_option($key, $value, '', 'no');
        } else {
            update_option($key, $value, false);
        }
    }
}
