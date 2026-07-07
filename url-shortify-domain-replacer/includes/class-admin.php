<?php

if (!defined('ABSPATH')) {
    exit;
}

class USDR_Admin {

    const PARENT_SLUG = 'url_shortify';
    const PAGE_SLUG = 'us-domain-replacer';

    public static function init() {
        add_action('kc_us_admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_menu', [__CLASS__, 'register_menu_fallback'], 999);
        add_filter('plugin_action_links_' . plugin_basename(USDR_PLUGIN_FILE), [__CLASS__, 'plugin_action_links']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_usdr_scan_links', [__CLASS__, 'ajax_scan_links']);
        add_action('wp_ajax_usdr_replace_links', [__CLASS__, 'ajax_replace_links']);
    }

    public static function register_menu() {
        if (self::page_registered()) {
            return;
        }

        add_submenu_page(
            self::PARENT_SLUG,
            __('Domain Replacer by Aris', 'us-domain-replacer'),
            __('Domain Replacer by Aris', 'us-domain-replacer'),
            'read',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function register_menu_fallback() {
        if (self::page_registered()) {
            return;
        }

        $parent = self::parent_menu_exists() ? self::PARENT_SLUG : 'tools.php';

        add_submenu_page(
            $parent,
            __('Domain Replacer by Aris', 'us-domain-replacer'),
            __('Domain Replacer by Aris', 'us-domain-replacer'),
            'read',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function plugin_action_links($links) {
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '">' . esc_html__('Open Domain Replacer by Aris', 'us-domain-replacer') . '</a>'
        );

        return $links;
    }

    private static function parent_menu_exists() {
        global $submenu;

        return !empty($submenu[self::PARENT_SLUG]);
    }

    private static function page_registered() {
        global $submenu;

        if (empty($submenu)) {
            return false;
        }

        foreach ($submenu as $items) {
            foreach ($items as $item) {
                if (!empty($item[2]) && $item[2] === self::PAGE_SLUG) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function current_user_can_manage() {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (function_exists('US') && isset(US()->access)) {
            return US()->access->can('manage_links');
        }

        return false;
    }

    public static function enqueue_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'usdr-admin',
            plugins_url('assets/admin.css', USDR_PLUGIN_FILE),
            [],
            USDR_VERSION
        );

        wp_enqueue_script(
            'usdr-admin',
            plugins_url('assets/admin.js', USDR_PLUGIN_FILE),
            ['jquery'],
            USDR_VERSION,
            true
        );

        $diagnostics = USDR_Replacer::get_diagnostics();

        wp_localize_script('usdr-admin', 'USDR', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('usdr_actions'),
            'diagnostics' => $diagnostics,
            'i18n' => [
                'scanning' => __('Scanning links...', 'us-domain-replacer'),
                'replacing' => __('Replacing domains...', 'us-domain-replacer'),
                'done' => __('Domain replacement completed.', 'us-domain-replacer'),
                'confirm' => __('This will update all matching URL Shortify target URLs. Continue?', 'us-domain-replacer'),
                'invalid' => __('Please enter both old and new domains.', 'us-domain-replacer'),
                'noMatches' => __('No matching links found for the old domain.', 'us-domain-replacer'),
                'jsMissing' => __('Admin script failed to load. Please hard-refresh this page or re-upload the plugin assets folder.', 'us-domain-replacer'),
                'ajaxFailed' => __('Request failed. See error details below.', 'us-domain-replacer'),
                'notReady' => __('URL Shortify is not ready. Fix the connection issues shown above before scanning.', 'us-domain-replacer'),
            ],
        ]);
    }

    public static function get_connection_status() {
        return USDR_Replacer::get_diagnostics();
    }

    public static function render_page() {
        if (!self::current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'us-domain-replacer'));
        }

        $status = self::get_connection_status();
        $ready = !empty($status['ready']);
        ?>
        <div class="wrap usdr-wrap">
            <h1><?php esc_html_e('URL Shortify Domain Replacer by Aris', 'us-domain-replacer'); ?></h1>
            <p class="description">
                <?php esc_html_e('Replace the target URL domain across all URL Shortify links. Short slugs and URL paths stay the same.', 'us-domain-replacer'); ?>
            </p>

            <div class="usdr-card usdr-status-card <?php echo $ready ? 'is-ready' : 'is-error'; ?>">
                <h2><?php esc_html_e('Connection Status', 'us-domain-replacer'); ?></h2>
                <ul class="usdr-checklist">
                    <li class="<?php echo !empty($status['shortify_active']) ? 'is-ok' : 'is-bad'; ?>">
                        <?php if (!empty($status['shortify_active'])) : ?>
                            <?php esc_html_e('URL Shortify plugin is active.', 'us-domain-replacer'); ?>
                        <?php else : ?>
                            <?php esc_html_e('URL Shortify plugin is not active. Activate it first.', 'us-domain-replacer'); ?>
                        <?php endif; ?>
                    </li>
                    <li class="<?php echo !empty($status['table_exists']) ? 'is-ok' : 'is-bad'; ?>">
                        <?php if (!empty($status['table_exists'])) : ?>
                            <?php
                            printf(
                                /* translators: 1: database table name, 2: number of links */
                                esc_html__('URL Shortify links table found (%1$s) with %2$d link(s).', 'us-domain-replacer'),
                                esc_html($status['table_name']),
                                (int) $status['total_links']
                            );
                            ?>
                        <?php else : ?>
                            <?php
                            printf(
                                /* translators: %s: database table name */
                                esc_html__('URL Shortify links table not found (%s). Re-save URL Shortify settings or reinstall URL Shortify.', 'us-domain-replacer'),
                                esc_html($status['table_name'])
                            );
                            ?>
                        <?php endif; ?>
                    </li>
                    <li class="is-ok">
                        <?php esc_html_e('REST API keys are NOT required. This tool reads and updates links directly inside WordPress.', 'us-domain-replacer'); ?>
                    </li>
                </ul>
                <?php if (!$ready) : ?>
                    <p class="usdr-inline-error">
                        <?php esc_html_e('Scan and replace are disabled until URL Shortify is connected properly.', 'us-domain-replacer'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="usdr-card">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="usdr-old-domain"><?php esc_html_e('Old Domain', 'us-domain-replacer'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="usdr-old-domain" class="regular-text" placeholder="olddomain.com" />
                            <p class="description"><?php esc_html_e('Domain to search for in target URLs.', 'us-domain-replacer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="usdr-new-domain"><?php esc_html_e('New Domain', 'us-domain-replacer'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="usdr-new-domain" class="regular-text" placeholder="newdomain.com" />
                            <p class="description"><?php esc_html_e('Replacement domain. Paths and slugs are preserved.', 'us-domain-replacer'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="button" class="button button-secondary" id="usdr-scan-btn" <?php disabled(!$ready); ?>>
                        <?php esc_html_e('Scan Links', 'us-domain-replacer'); ?>
                    </button>
                    <button type="button" class="button button-primary" id="usdr-replace-btn" disabled>
                        <?php esc_html_e('Replace All Matching Links', 'us-domain-replacer'); ?>
                    </button>
                </p>
            </div>

            <noscript>
                <div class="notice notice-error"><p><?php esc_html_e('JavaScript is required to scan and replace links.', 'us-domain-replacer'); ?></p></div>
            </noscript>

            <div id="usdr-status" class="usdr-status" aria-live="polite"></div>
            <div id="usdr-summary" class="usdr-summary"></div>
            <div id="usdr-results" class="usdr-results"></div>
        </div>
        <?php
    }

    private static function verify_request() {
        if (!self::current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied.', 'us-domain-replacer')], 403);
        }

        check_ajax_referer('usdr_actions', 'nonce');
    }

    public static function ajax_scan_links() {
        self::verify_request();

        $diagnostics = USDR_Replacer::get_diagnostics();
        if (empty($diagnostics['ready'])) {
            wp_send_json_error([
                'message' => $diagnostics['error'] ?: __('URL Shortify is not connected properly.', 'us-domain-replacer'),
                'diagnostics' => $diagnostics,
            ]);
        }
        $old_domain = USDR_Replacer::normalize_domain(sanitize_text_field(wp_unslash($_POST['old_domain'] ?? '')));
        $new_domain = USDR_Replacer::normalize_domain(sanitize_text_field(wp_unslash($_POST['new_domain'] ?? '')));

        if ($old_domain === '' || $new_domain === '') {
            wp_send_json_error(['message' => __('Please provide valid old and new domains.', 'us-domain-replacer')]);
        }

        if ($old_domain === $new_domain) {
            wp_send_json_error(['message' => __('Old and new domains must be different.', 'us-domain-replacer')]);
        }

        $count = USDR_Replacer::count_matching_links($old_domain);
        $preview = USDR_Replacer::get_matching_links($old_domain, 20, 0);

        foreach ($preview as &$item) {
            $item['new_url'] = USDR_Replacer::replace_domain_in_url($item['old_url'], $old_domain, $new_domain);
        }
        unset($item);

        wp_send_json_success([
            'count' => $count,
            'preview' => $preview,
            'old_domain' => $old_domain,
            'new_domain' => $new_domain,
        ]);
    }

    public static function ajax_replace_links() {
        self::verify_request();

        $diagnostics = USDR_Replacer::get_diagnostics();
        if (empty($diagnostics['ready'])) {
            wp_send_json_error([
                'message' => $diagnostics['error'] ?: __('URL Shortify is not connected properly.', 'us-domain-replacer'),
                'diagnostics' => $diagnostics,
            ]);
        }

        $old_domain = sanitize_text_field(wp_unslash($_POST['old_domain'] ?? ''));
        $new_domain = sanitize_text_field(wp_unslash($_POST['new_domain'] ?? ''));
        $offset = absint($_POST['offset'] ?? 0);

        $result = USDR_Replacer::process_batch($old_domain, $new_domain, $offset);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }
}
