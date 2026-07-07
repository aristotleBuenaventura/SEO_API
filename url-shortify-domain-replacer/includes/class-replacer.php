<?php

if (!defined('ABSPATH')) {
    exit;
}

class USDR_Replacer {

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kc_us_links';
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function normalize_domain($domain) {
        $domain = trim((string) $domain);
        if ($domain === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $domain)) {
            $domain = 'https://' . $domain;
        }

        $parsed = wp_parse_url($domain);
        if (empty($parsed['host'])) {
            return '';
        }

        $host = strtolower($parsed['host']);
        return preg_replace('/^www\./', '', $host);
    }

    public static function host_from_url($url) {
        $parsed = wp_parse_url($url);
        if (empty($parsed['host'])) {
            return '';
        }

        $host = strtolower($parsed['host']);
        return preg_replace('/^www\./', '', $host);
    }

    public static function replace_domain_in_url($url, $old_domain, $new_domain) {
        $parsed = wp_parse_url($url);
        if (empty($parsed['host'])) {
            return $url;
        }

        $host = self::host_from_url($url);
        if ($host !== $old_domain) {
            return $url;
        }

        $scheme = !empty($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $new_url = $scheme . '://' . $new_domain;

        if (!empty($parsed['port'])) {
            $new_url .= ':' . $parsed['port'];
        }
        if (!empty($parsed['path'])) {
            $new_url .= $parsed['path'];
        }
        if (!empty($parsed['query'])) {
            $new_url .= '?' . $parsed['query'];
        }
        if (!empty($parsed['fragment'])) {
            $new_url .= '#' . $parsed['fragment'];
        }

        return $new_url;
    }

    public static function get_diagnostics() {
        $shortify_active = class_exists('KaizenCoders\URL_Shortify\Helper');
        $table_name = self::table_name();
        $table_exists = self::table_exists();
        $total_links = $table_exists ? self::count_all_links() : 0;
        $ready = $shortify_active && $table_exists;
        $error = '';

        if (!$shortify_active) {
            $error = __('URL Shortify plugin is not active.', 'us-domain-replacer');
        } elseif (!$table_exists) {
            $error = sprintf(
                /* translators: %s: database table name */
                __('URL Shortify links table was not found (%s).', 'us-domain-replacer'),
                $table_name
            );
        }

        return [
            'ready' => $ready,
            'shortify_active' => $shortify_active,
            'table_exists' => $table_exists,
            'table_name' => $table_name,
            'total_links' => $total_links,
            'api_required' => false,
            'error' => $error,
        ];
    }

    public static function count_all_links() {
        global $wpdb;

        if (!self::table_exists()) {
            return 0;
        }

        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function count_matching_links($old_domain) {
        return count(self::find_matching_link_ids($old_domain));
    }

    /**
     * @return array<int, array{id:int, name:string, slug:string, old_url:string}>
     */
    public static function get_all_matching_links($old_domain) {
        global $wpdb;

        $old_domain = self::normalize_domain($old_domain);
        if ($old_domain === '' || !self::table_exists()) {
            return [];
        }

        $table = self::table_name();
        $like_host = '%' . $wpdb->esc_like($old_domain) . '%';
        $like_www = '%' . $wpdb->esc_like('www.' . $old_domain) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, slug, url FROM {$table}
             WHERE url LIKE %s OR url LIKE %s
             ORDER BY id ASC",
            $like_host,
            $like_www
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $matches = [];
        foreach ($rows as $row) {
            if (self::host_from_url($row['url']) !== $old_domain) {
                continue;
            }

            $matches[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'old_url' => $row['url'],
            ];
        }

        return $matches;
    }

    /**
     * @return int[]
     */
    public static function find_matching_link_ids($old_domain) {
        global $wpdb;

        $old_domain = self::normalize_domain($old_domain);
        if ($old_domain === '' || !self::table_exists()) {
            return [];
        }

        $table = self::table_name();
        $like_host = '%' . $wpdb->esc_like($old_domain) . '%';
        $like_www = '%' . $wpdb->esc_like('www.' . $old_domain) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, url FROM {$table} WHERE url LIKE %s OR url LIKE %s",
            $like_host,
            $like_www
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (self::host_from_url($row['url']) === $old_domain) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @return true|null True when updated, null when skipped or failed.
     */
    private static function update_link_domain($id, $old_domain, $new_domain, $user_id, $now) {
        global $wpdb;

        $id = (int) $id;
        $table = self::table_name();
        $old_url = '';

        if (function_exists('US') && isset(US()->db->links)) {
            $link = US()->db->links->get($id);
            if (is_array($link) && !empty($link['url'])) {
                $old_url = $link['url'];
            }
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $old_url = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT url FROM {$table} WHERE id = %d",
                $id
            ));
        }

        if ($old_url === '' || self::host_from_url($old_url) !== $old_domain) {
            return null;
        }

        $new_url = self::replace_domain_in_url($old_url, $old_domain, $new_domain);
        if ($new_url === $old_url) {
            return null;
        }

        if (function_exists('US') && isset(US()->db->links)) {
            $link = US()->db->links->get($id);
            if (!is_array($link)) {
                return null;
            }

            $link['url'] = $new_url;
            $link['updated_at'] = $now;
            $link['updated_by_id'] = $user_id;
            $saved = US()->db->links->update($id, $link);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $saved = $wpdb->update(
                $table,
                [
                    'url' => $new_url,
                    'updated_at' => $now,
                    'updated_by_id' => $user_id,
                ],
                ['id' => $id],
                ['%s', '%s', '%d'],
                ['%d']
            );
            $saved = $saved !== false;
        }

        if (!$saved) {
            return null;
        }

        do_action('kc_us_link_updated', $id);

        return true;
    }

    public static function process_all($old_domain, $new_domain) {
        $old_domain = self::normalize_domain($old_domain);
        $new_domain = self::normalize_domain($new_domain);

        if ($old_domain === '' || $new_domain === '') {
            return new WP_Error('invalid_domain', __('Please provide valid old and new domains.', 'us-domain-replacer'));
        }

        if ($old_domain === $new_domain) {
            return new WP_Error('same_domain', __('Old and new domains must be different.', 'us-domain-replacer'));
        }

        if (!self::table_exists()) {
            return new WP_Error('missing_table', __('URL Shortify links table was not found.', 'us-domain-replacer'));
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $all_ids = self::find_matching_link_ids($old_domain);
        $total_matches = count($all_ids);
        $updated = 0;
        $skipped = 0;
        $user_id = get_current_user_id();
        $now = current_time('mysql');

        foreach ($all_ids as $id) {
            if (self::update_link_domain($id, $old_domain, $new_domain, $user_id, $now) === true) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'processed' => $total_matches,
            'total_matches' => $total_matches,
            'has_more' => false,
        ];
    }
}
