<?php

if (!defined('ABSPATH')) {
    exit;
}

class USDR_GSheet {

    const SPREADSHEET_ID = '1XFv3f7L_is3ul4E5-wTo97QwfawcWab86BxJVtQuEqc';
    const CACHE_TTL = 900;
    const KEYS_TRANSIENT = 'usdr_gsheet_keys';
    const INDEX_TRANSIENT = 'usdr_gsheet_index';
    const TITLE_TRANSIENT = 'usdr_gsheet_title';
    const TOKEN_TRANSIENT = 'usdr_gsheet_access_token';
    const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    public static function spreadsheet_url() {
        return 'https://docs.google.com/spreadsheets/d/' . self::SPREADSHEET_ID . '/edit';
    }

    public static function service_account_email() {
        $credentials = self::load_credentials();
        if (is_wp_error($credentials)) {
            return '';
        }

        return (string) ($credentials['client_email'] ?? '');
    }

    public static function spreadsheet_title() {
        $cached = get_transient(self::TITLE_TRANSIENT);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $data = self::api_get(
            'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode(self::SPREADSHEET_ID),
            ['fields' => 'properties.title']
        );

        if (is_wp_error($data)) {
            return __('MCW Shortlinks Summary', 'us-domain-replacer');
        }

        $title = trim((string) ($data['properties']['title'] ?? ''));
        if ($title === '') {
            $title = __('MCW Shortlinks Summary', 'us-domain-replacer');
        }

        set_transient(self::TITLE_TRANSIENT, $title, self::CACHE_TTL);

        return $title;
    }

    /**
     * @return array<int, array{name:string, gid:string}>|WP_Error
     */
    public static function get_brands() {
        $index = self::get_sheet_index();
        if (is_wp_error($index)) {
            return $index;
        }

        return $index;
    }

    /**
     * @return array<int, array{code:string, slug_count:int}>|WP_Error
     */
    public static function get_languages($brand) {
        $sheet = self::get_sheet_data($brand);
        if (is_wp_error($sheet)) {
            return $sheet;
        }

        $languages = [];
        foreach ($sheet['slugs_by_language'] as $code => $slugs) {
            $languages[] = [
                'code' => $code,
                'slug_count' => count($slugs),
            ];
        }

        usort($languages, static function ($a, $b) {
            return strcasecmp($a['code'], $b['code']);
        });

        return $languages;
    }

    /**
     * @return string[]|WP_Error
     */
    public static function get_slugs($brand, $language) {
        $sheet = self::get_sheet_data($brand);
        if (is_wp_error($sheet)) {
            return $sheet;
        }

        $language = self::normalize_text($language);
        if ($language === '') {
            return new WP_Error('missing_language', __('Please select a language.', 'us-domain-replacer'));
        }

        $map = [];
        foreach ($sheet['slugs_by_language'] as $code => $slugs) {
            $map[self::normalize_text($code)] = $slugs;
        }

        if (!isset($map[$language])) {
            return new WP_Error(
                'unknown_language',
                sprintf(
                    /* translators: 1: language, 2: brand/sheet name */
                    __('Language "%1$s" was not found for brand "%2$s".', 'us-domain-replacer'),
                    $language,
                    $brand
                )
            );
        }

        return array_values($map[$language]);
    }

    public static function clear_cache() {
        $keys = get_transient(self::KEYS_TRANSIENT);
        if (!is_array($keys)) {
            $keys = [];
        }

        $keys[] = self::KEYS_TRANSIENT;
        $keys[] = self::INDEX_TRANSIENT;
        $keys[] = self::TITLE_TRANSIENT;

        foreach (array_unique($keys) as $key) {
            delete_transient($key);
        }

        return true;
    }

    /**
     * @return array<int, array{name:string, gid:string}>|WP_Error
     */
    private static function get_sheet_index() {
        $cached = get_transient(self::INDEX_TRANSIENT);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $data = self::api_get(
            'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode(self::SPREADSHEET_ID),
            ['fields' => 'sheets.properties(title,sheetId)']
        );
        if (is_wp_error($data)) {
            return $data;
        }

        $brands = [];
        $seen = [];

        foreach (($data['sheets'] ?? []) as $sheet) {
            $name = trim((string) ($sheet['properties']['title'] ?? ''));
            $gid = isset($sheet['properties']['sheetId']) ? (string) $sheet['properties']['sheetId'] : '';

            if ($name === '' || $gid === '' || isset($seen[$name]) || self::is_skipped_sheet($name)) {
                continue;
            }

            $seen[$name] = true;
            $brands[] = [
                'name' => $name,
                'gid' => $gid,
            ];
        }

        if (empty($brands)) {
            return new WP_Error(
                'gsheet_index',
                sprintf(
                    /* translators: %s: service account email */
                    __('Could not read brand sheet names. Share the private spreadsheet with %s as Viewer.', 'us-domain-replacer'),
                    self::service_account_email() ?: 'the service account'
                )
            );
        }

        usort($brands, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        self::set_cache(self::INDEX_TRANSIENT, $brands);

        return $brands;
    }

    /**
     * @return array{name:string, gid:string, slugs_by_language:array<string, string[]>}|WP_Error
     */
    private static function get_sheet_data($brand) {
        $brand = trim((string) $brand);
        if ($brand === '') {
            return new WP_Error('missing_brand', __('Please select a brand.', 'us-domain-replacer'));
        }

        $index = self::get_sheet_index();
        if (is_wp_error($index)) {
            return $index;
        }

        $gid = '';
        $matched_name = '';
        foreach ($index as $item) {
            if (strcasecmp($item['name'], $brand) === 0) {
                $gid = $item['gid'];
                $matched_name = $item['name'];
                break;
            }
        }

        if ($gid === '') {
            return new WP_Error(
                'unknown_brand',
                sprintf(
                    /* translators: %s: brand/sheet name */
                    __('Brand "%s" was not found in Google Sheets.', 'us-domain-replacer'),
                    $brand
                )
            );
        }

        $cache_key = 'usdr_gsheet_rows_' . md5($gid);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['slugs_by_language'])) {
            return $cached;
        }

        $range = "'" . str_replace("'", "''", $matched_name) . "'!A:F";
        $data = self::api_get(
            'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode(self::SPREADSHEET_ID) . '/values/' . rawurlencode($range)
        );
        if (is_wp_error($data)) {
            return $data;
        }

        $rows = isset($data['values']) && is_array($data['values']) ? $data['values'] : [];
        if (empty($rows)) {
            return new WP_Error(
                'gsheet_empty',
                sprintf(
                    /* translators: %s: brand/sheet name */
                    __('Google Sheet "%s" returned no rows.', 'us-domain-replacer'),
                    $matched_name
                )
            );
        }

        $header = array_map([__CLASS__, 'normalize_header'], $rows[0]);
        $language_index = self::find_column_index($header, ['langauge', 'language'], 2);
        $slug_index = self::find_column_index($header, ['slug'], 5);

        $slugs_by_language = [];
        foreach (array_slice($rows, 1) as $row) {
            $language = isset($row[$language_index]) ? trim((string) $row[$language_index]) : '';
            $slug = isset($row[$slug_index]) ? trim((string) $row[$slug_index]) : '';

            if ($language === '' || $slug === '') {
                continue;
            }

            if (in_array(strtolower($language), ['langauge', 'language'], true) || strtolower($slug) === 'slug') {
                continue;
            }

            if (!isset($slugs_by_language[$language])) {
                $slugs_by_language[$language] = [];
            }

            $slugs_by_language[$language][$slug] = $slug;
        }

        foreach ($slugs_by_language as $language => $slugs) {
            $slugs_by_language[$language] = array_values($slugs);
        }

        if (empty($slugs_by_language)) {
            return new WP_Error(
                'gsheet_no_slugs',
                sprintf(
                    /* translators: %s: brand/sheet name */
                    __('No language/slug rows were found in Google Sheet "%s".', 'us-domain-replacer'),
                    $matched_name
                )
            );
        }

        $payload = [
            'name' => $matched_name,
            'gid' => $gid,
            'slugs_by_language' => $slugs_by_language,
        ];

        self::set_cache($cache_key, $payload);

        return $payload;
    }

    private static function is_skipped_sheet($name) {
        return strcasecmp($name, 'Summary') === 0;
    }

    /**
     * @param array<string, string> $query
     * @return array|WP_Error
     */
    private static function api_get($url, $query = []) {
        $token = self::get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_get($url, [
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        return self::decode_google_response($response);
    }

    /**
     * @return string|WP_Error
     */
    private static function get_access_token() {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if (is_array($cached) && !empty($cached['token']) && !empty($cached['expires']) && (int) $cached['expires'] > time() + 60) {
            return (string) $cached['token'];
        }

        $credentials = self::load_credentials();
        if (is_wp_error($credentials)) {
            return $credentials;
        }

        $jwt = self::create_jwt($credentials);
        if (is_wp_error($jwt)) {
            return $jwt;
        }

        $response = wp_remote_post((string) $credentials['token_uri'], [
            'timeout' => 20,
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        $data = self::decode_google_response($response);
        if (is_wp_error($data)) {
            return $data;
        }

        $token = trim((string) ($data['access_token'] ?? ''));
        $expires_in = (int) ($data['expires_in'] ?? 3600);
        if ($token === '') {
            return new WP_Error('gsheet_token', __('Google did not return an access token for the service account.', 'us-domain-replacer'));
        }

        set_transient(self::TOKEN_TRANSIENT, [
            'token' => $token,
            'expires' => time() + max(60, $expires_in),
        ], max(60, $expires_in - 60));

        return $token;
    }

    /**
     * @param array<string, mixed> $credentials
     * @return string|WP_Error
     */
    private static function create_jwt(array $credentials) {
        if (!function_exists('openssl_sign')) {
            return new WP_Error('gsheet_openssl', __('PHP OpenSSL is required to authenticate with Google Sheets.', 'us-domain-replacer'));
        }

        $header = self::b64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $payload = self::b64url(wp_json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signing_input = $header . '.' . $payload;
        $signature = '';
        $ok = openssl_sign($signing_input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok || $signature === '') {
            return new WP_Error('gsheet_jwt', __('Could not sign the Google service account request.', 'us-domain-replacer'));
        }

        return $signing_input . '.' . self::b64url($signature);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private static function load_credentials() {
        $path = USDR_PLUGIN_DIR . 'includes/credentials.json';
        if (!is_readable($path)) {
            return new WP_Error(
                'gsheet_credentials',
                __('Google service account credentials.json is missing from the plugin includes folder.', 'us-domain-replacer')
            );
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key']) || empty($decoded['token_uri'])) {
            return new WP_Error('gsheet_credentials', __('Google service account credentials.json is invalid.', 'us-domain-replacer'));
        }

        return $decoded;
    }

    /**
     * @param array|WP_Error $response
     * @return array|WP_Error
     */
    private static function decode_google_response($response) {
        if (is_wp_error($response)) {
            return new WP_Error(
                'gsheet_http',
                sprintf(
                    /* translators: %s: error message */
                    __('Google Sheets request failed: %s', 'us-domain-replacer'),
                    $response->get_error_message()
                )
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($code < 200 || $code >= 300) {
            $message = (string) ($data['error']['message'] ?? $data['error_description'] ?? '');
            if ($code === 403) {
                return new WP_Error(
                    'gsheet_forbidden',
                    sprintf(
                        /* translators: %s: service account email */
                        __('Google Sheets access denied. Share the spreadsheet with %s as Viewer, and enable the Google Sheets API.', 'us-domain-replacer'),
                        self::service_account_email() ?: 'the service account'
                    )
                );
            }

            return new WP_Error(
                'gsheet_http',
                $message !== ''
                    ? sprintf(
                        /* translators: 1: HTTP status, 2: Google error */
                        __('Google Sheets returned HTTP %1$d: %2$s', 'us-domain-replacer'),
                        $code,
                        $message
                    )
                    : sprintf(
                        /* translators: %d: HTTP status code */
                        __('Google Sheets returned HTTP %d.', 'us-domain-replacer'),
                        $code
                    )
            );
        }

        return $data;
    }

    private static function b64url($data) {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }

    private static function normalize_header($value) {
        return strtolower(trim((string) $value));
    }

    private static function normalize_text($value) {
        return strtoupper(trim((string) $value));
    }

    /**
     * @param string[] $header
     * @param string[] $names
     */
    private static function find_column_index(array $header, array $names, $fallback) {
        foreach ($names as $name) {
            $index = array_search($name, $header, true);
            if ($index !== false) {
                return (int) $index;
            }
        }

        return (int) $fallback;
    }

    private static function set_cache($key, $value) {
        set_transient($key, $value, self::CACHE_TTL);

        $keys = get_transient(self::KEYS_TRANSIENT);
        if (!is_array($keys)) {
            $keys = [];
        }

        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            set_transient(self::KEYS_TRANSIENT, $keys, DAY_IN_SECONDS);
        }
    }
}
