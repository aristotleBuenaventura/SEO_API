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
        add_action('wp_ajax_usdr_get_brands', [__CLASS__, 'ajax_get_brands']);
        add_action('wp_ajax_usdr_get_languages', [__CLASS__, 'ajax_get_languages']);
        add_action('wp_ajax_usdr_refresh_sheet', [__CLASS__, 'ajax_refresh_sheet']);
        add_action('in_admin_header', [__CLASS__, 'suppress_foreign_notices'], 1000);
        add_filter('admin_body_class', [__CLASS__, 'admin_body_class']);
    }

    public static function admin_body_class($classes) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === self::PAGE_SLUG) {
            $classes .= ' usdr-admin-page';
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
        if (self::page_registered()) {
            return;
        }

        add_submenu_page(
            self::PARENT_SLUG,
            __('Domain Replacer', 'us-domain-replacer'),
            __('Domain Replacer', 'us-domain-replacer'),
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
            __('Domain Replacer', 'us-domain-replacer'),
            __('Domain Replacer', 'us-domain-replacer'),
            'read',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function plugin_action_links($links) {
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '">' . esc_html__('Open Domain Replacer', 'us-domain-replacer') . '</a>'
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

        wp_enqueue_style('dashicons');
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
                'replacing' => __('Replacing filtered links, please wait...', 'us-domain-replacer'),
                'done' => __('Domain replacement completed.', 'us-domain-replacer'),
                'confirm' => __('This will update %d selected link(s) for the chosen brand and language.', 'us-domain-replacer'),
                'confirmTitle' => __('Confirm domain replacement', 'us-domain-replacer'),
                'confirmOk' => __('Replace selected links', 'us-domain-replacer'),
                'confirmCancel' => __('Cancel', 'us-domain-replacer'),
                'noneSelected' => __('Select at least one link to replace.', 'us-domain-replacer'),
                'selectedCount' => __('%1$d of %2$d selected', 'us-domain-replacer'),
                'invalid' => __('Please select a brand and language, then enter both old and new domains.', 'us-domain-replacer'),
                'noMatches' => __('No matching links found for the selected brand, language, and old domain.', 'us-domain-replacer'),
                'jsMissing' => __('Admin script failed to load. Please hard-refresh this page or re-upload the plugin assets folder.', 'us-domain-replacer'),
                'ajaxFailed' => __('Request failed. See error details below.', 'us-domain-replacer'),
                'notReady' => __('URL Shortify is not ready. Fix the connection issues shown above before scanning.', 'us-domain-replacer'),
                'loadingBrands' => __('Loading brands from Google Sheets...', 'us-domain-replacer'),
                'loadingLanguages' => __('Loading languages...', 'us-domain-replacer'),
                'selectBrand' => __('Select a brand', 'us-domain-replacer'),
                'selectLanguage' => __('Select a language', 'us-domain-replacer'),
                'noLanguages' => __('No languages found for this brand.', 'us-domain-replacer'),
                'sheetFailed' => __('Could not load Google Sheets data.', 'us-domain-replacer'),
                'refreshing' => __('Refreshing Google Sheets data...', 'us-domain-replacer'),
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
        $service_email = USDR_GSheet::service_account_email();
        $sheet_title = USDR_GSheet::spreadsheet_title();

        if ($ready) {
            $pill_class = 'usdr-pill usdr-pill-ok';
            $pill_label = __('Ready', 'us-domain-replacer');
        } else {
            $pill_class = 'usdr-pill usdr-pill-fail';
            $pill_label = __('Not ready', 'us-domain-replacer');
        }
        ?>
        <div class="wrap usdr-app">
            <div class="usdr-shell">
                <header class="usdr-hero">
                    <div class="usdr-hero-top">
                        <div class="usdr-brand">
                            <div class="usdr-mark" aria-hidden="true"><span class="dashicons dashicons-admin-links"></span></div>
                            <div>
                                <p class="usdr-eyebrow"><?php esc_html_e('URL Shortify Tool', 'us-domain-replacer'); ?></p>
                                <h1><?php esc_html_e('Domain Replacer', 'us-domain-replacer'); ?></h1>
                                <p><?php esc_html_e('Bulk-update target URL domains for filtered short links. Scope is controlled by brand, language, and Google Sheet slugs.', 'us-domain-replacer'); ?></p>
                            </div>
                        </div>
                        <div class="usdr-hero-meta">
                            <span class="<?php echo esc_attr($pill_class); ?>"><?php echo esc_html($pill_label); ?></span>
                            <span class="usdr-version-chip">v<?php echo esc_html(USDR_VERSION); ?></span>
                        </div>
                    </div>
                </header>

                <div id="usdr-status" class="usdr-flash-area" aria-live="polite"></div>

                <div class="usdr-layout">
                    <div class="usdr-main">
                        <section class="usdr-card">
                            <div class="usdr-card-head">
                                <div>
                                    <h2><?php esc_html_e('Configuration', 'us-domain-replacer'); ?></h2>
                                    <p class="usdr-card-lead"><?php esc_html_e('Define the Google Sheet scope and domain swap before running a scan.', 'us-domain-replacer'); ?></p>
                                </div>
                            </div>

                            <div class="usdr-form-section">
                                <h3 class="usdr-form-section-title"><?php esc_html_e('Sheet filter', 'us-domain-replacer'); ?></h3>
                                <div class="usdr-form-grid">
                                    <div class="usdr-field">
                                        <label for="usdr-brand"><?php esc_html_e('Brand', 'us-domain-replacer'); ?></label>
                                        <select id="usdr-brand">
                                            <option value=""><?php esc_html_e('Loading brands...', 'us-domain-replacer'); ?></option>
                                        </select>
                                        <p class="usdr-help"><?php esc_html_e('Sheet tab name. Summary tab is excluded.', 'us-domain-replacer'); ?></p>
                                    </div>
                                    <div class="usdr-field">
                                        <label for="usdr-language"><?php esc_html_e('Language', 'us-domain-replacer'); ?></label>
                                        <select id="usdr-language" disabled>
                                            <option value=""><?php esc_html_e('Select a brand first', 'us-domain-replacer'); ?></option>
                                        </select>
                                        <p class="usdr-help"><?php esc_html_e('Column C. Slugs filtered from column F.', 'us-domain-replacer'); ?></p>
                                    </div>
                                </div>
                                <div class="usdr-inline-action">
                                    <button type="button" class="usdr-btn usdr-btn-ghost usdr-btn-sm" id="usdr-refresh-sheet">
                                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                        <?php esc_html_e('Reload Google Sheet', 'us-domain-replacer'); ?>
                                    </button>
                                </div>
                            </div>

                            <div class="usdr-form-section">
                                <h3 class="usdr-form-section-title"><?php esc_html_e('Domain swap', 'us-domain-replacer'); ?></h3>
                                <div class="usdr-domain-swap">
                                    <div class="usdr-field">
                                        <label for="usdr-old-domain"><?php esc_html_e('Old domain', 'us-domain-replacer'); ?></label>
                                        <input type="text" id="usdr-old-domain" placeholder="olddomain.com" autocomplete="off" spellcheck="false" />
                                    </div>
                                    <div class="usdr-swap-arrow" aria-hidden="true">→</div>
                                    <div class="usdr-field">
                                        <label for="usdr-new-domain"><?php esc_html_e('New domain', 'us-domain-replacer'); ?></label>
                                        <input type="text" id="usdr-new-domain" placeholder="newdomain.com" autocomplete="off" spellcheck="false" />
                                    </div>
                                </div>
                                <p class="usdr-help usdr-help-block"><?php esc_html_e('Only the hostname changes. Slugs and URL paths remain unchanged.', 'us-domain-replacer'); ?></p>
                            </div>

                            <div class="usdr-action-bar">
                                <p class="usdr-action-note"><?php esc_html_e('Scan first to preview affected links, then run replace.', 'us-domain-replacer'); ?></p>
                                <div class="usdr-actions">
                                    <button type="button" class="usdr-btn usdr-btn-secondary" id="usdr-scan-btn" <?php disabled(!$ready); ?>>
                                        <?php esc_html_e('Scan links', 'us-domain-replacer'); ?>
                                    </button>
                                    <button type="button" class="usdr-btn usdr-btn-primary" id="usdr-replace-btn" disabled>
                                        <?php esc_html_e('Replace selected links', 'us-domain-replacer'); ?>
                                    </button>
                                </div>
                            </div>
                        </section>
                    </div>

                    <aside class="usdr-sidebar">
                        <section class="usdr-card usdr-card-compact">
                            <div class="usdr-card-head usdr-card-head-tight">
                                <h2><?php esc_html_e('System status', 'us-domain-replacer'); ?></h2>
                            </div>

                            <dl class="usdr-meta">
                                <div class="usdr-meta-row">
                                    <dt><?php esc_html_e('URL Shortify', 'us-domain-replacer'); ?></dt>
                                    <dd class="<?php echo !empty($status['shortify_active']) ? 'is-ok' : 'is-bad'; ?>">
                                        <?php echo !empty($status['shortify_active'])
                                            ? esc_html__('Active', 'us-domain-replacer')
                                            : esc_html__('Inactive', 'us-domain-replacer'); ?>
                                    </dd>
                                </div>
                                <div class="usdr-meta-row">
                                    <dt><?php esc_html_e('Links table', 'us-domain-replacer'); ?></dt>
                                    <dd class="<?php echo !empty($status['table_exists']) ? 'is-ok' : 'is-bad'; ?>">
                                        <?php if (!empty($status['table_exists'])) : ?>
                                            <?php echo esc_html(number_format_i18n((int) $status['total_links'])); ?>
                                            <?php esc_html_e('links', 'us-domain-replacer'); ?>
                                        <?php else : ?>
                                            <?php esc_html_e('Not found', 'us-domain-replacer'); ?>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="usdr-meta-row">
                                    <dt><?php esc_html_e('Sheet name', 'us-domain-replacer'); ?></dt>
                                    <dd>
                                        <a href="<?php echo esc_url(USDR_GSheet::spreadsheet_url()); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo esc_html($sheet_title); ?>
                                        </a>
                                    </dd>
                                </div>
                                <div class="usdr-meta-row">
                                    <dt><?php esc_html_e('Service account', 'us-domain-replacer'); ?></dt>
                                    <dd><code><?php echo esc_html($service_email); ?></code></dd>
                                </div>
                            </dl>

                            <?php if (!$ready) : ?>
                                <p class="usdr-inline-error">
                                    <?php esc_html_e('Scan and replace are disabled until URL Shortify is connected properly.', 'us-domain-replacer'); ?>
                                </p>
                            <?php endif; ?>
                        </section>
                    </aside>
                </div>

                <section class="usdr-card usdr-results-panel is-hidden" id="usdr-results-section">
                    <div class="usdr-card-head">
                        <div>
                            <h2><?php esc_html_e('Scan results', 'us-domain-replacer'); ?></h2>
                            <p class="usdr-card-lead"><?php esc_html_e('Review matching links before applying the domain replacement. Uncheck rows to exclude them, or drag across rows to select or unselect multiple.', 'us-domain-replacer'); ?></p>
                        </div>
                    </div>
                    <div id="usdr-summary"></div>
                    <div id="usdr-results"></div>
                </section>
            </div>

            <div id="usdr-modal" class="usdr-modal" hidden aria-hidden="true">
                <div class="usdr-modal-backdrop" data-usdr-modal-close></div>
                <div class="usdr-modal-card" role="dialog" aria-modal="true" aria-labelledby="usdr-modal-title">
                    <h3 id="usdr-modal-title"></h3>
                    <p id="usdr-modal-message"></p>
                    <div class="usdr-modal-actions">
                        <button type="button" class="usdr-btn usdr-btn-secondary" id="usdr-modal-cancel">
                            <?php esc_html_e('Cancel', 'us-domain-replacer'); ?>
                        </button>
                        <button type="button" class="usdr-btn usdr-btn-primary" id="usdr-modal-confirm">
                            <?php esc_html_e('Replace links', 'us-domain-replacer'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <noscript>
                <div class="usdr-flash is-error"><?php esc_html_e('JavaScript is required to scan and replace links.', 'us-domain-replacer'); ?></div>
            </noscript>

            <footer class="usdr-footer">
                <span><?php esc_html_e('Developed by Aris', 'us-domain-replacer'); ?></span>
            </footer>
        </div>
        <?php
    }

    private static function verify_request() {
        if (!self::current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied.', 'us-domain-replacer')], 403);
        }

        check_ajax_referer('usdr_actions', 'nonce');
    }

    public static function ajax_get_brands() {
        self::verify_request();

        $brands = USDR_GSheet::get_brands();
        if (is_wp_error($brands)) {
            wp_send_json_error(['message' => $brands->get_error_message()]);
        }

        wp_send_json_success([
            'brands' => $brands,
        ]);
    }

    public static function ajax_get_languages() {
        self::verify_request();

        $brand = sanitize_text_field(wp_unslash($_POST['brand'] ?? ''));
        $languages = USDR_GSheet::get_languages($brand);
        if (is_wp_error($languages)) {
            wp_send_json_error(['message' => $languages->get_error_message()]);
        }

        wp_send_json_success([
            'brand' => $brand,
            'languages' => $languages,
        ]);
    }

    public static function ajax_refresh_sheet() {
        self::verify_request();

        USDR_GSheet::clear_cache();

        $brands = USDR_GSheet::get_brands();
        if (is_wp_error($brands)) {
            wp_send_json_error(['message' => $brands->get_error_message()]);
        }

        wp_send_json_success([
            'brands' => $brands,
            'message' => __('Google Sheets data reloaded.', 'us-domain-replacer'),
        ]);
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

        $filter = self::request_filter();
        if (is_wp_error($filter)) {
            wp_send_json_error(['message' => $filter->get_error_message()]);
        }

        $old_domain = $filter['old_domain'];
        $new_domain = $filter['new_domain'];
        $matches = USDR_Replacer::get_all_matching_links($old_domain, $filter['slugs']);
        $count = count($matches);

        foreach ($matches as &$item) {
            $item['new_url'] = USDR_Replacer::replace_domain_in_url($item['old_url'], $old_domain, $new_domain);
        }
        unset($item);

        wp_send_json_success([
            'count' => $count,
            'preview' => $matches,
            'old_domain' => $old_domain,
            'new_domain' => $new_domain,
            'brand' => $filter['brand'],
            'language' => $filter['language'],
            'sheet_slugs' => count($filter['slugs']),
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

        $filter = self::request_filter();
        if (is_wp_error($filter)) {
            wp_send_json_error(['message' => $filter->get_error_message()]);
        }

        $link_ids = self::request_link_ids();
        if (empty($link_ids)) {
            wp_send_json_error(['message' => __('Select at least one link to replace.', 'us-domain-replacer')]);
        }

        $matches = USDR_Replacer::get_all_matching_links($filter['old_domain'], $filter['slugs']);
        $valid_ids = array_map(static function ($item) {
            return (int) $item['id'];
        }, $matches);
        $link_ids = array_values(array_intersect($link_ids, $valid_ids));

        if (empty($link_ids)) {
            wp_send_json_error(['message' => __('None of the selected links match the current scan filters.', 'us-domain-replacer')]);
        }

        $result = USDR_Replacer::process_all($filter['old_domain'], $filter['new_domain'], $filter['slugs'], $link_ids);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $result['brand'] = $filter['brand'];
        $result['language'] = $filter['language'];
        $result['sheet_slugs'] = count($filter['slugs']);

        wp_send_json_success($result);
    }

    /**
     * @return array{brand:string, language:string, old_domain:string, new_domain:string, slugs:string[]}|WP_Error
     */
    private static function request_filter() {
        $brand = sanitize_text_field(wp_unslash($_POST['brand'] ?? ''));
        $language = sanitize_text_field(wp_unslash($_POST['language'] ?? ''));
        $old_domain = USDR_Replacer::normalize_domain(sanitize_text_field(wp_unslash($_POST['old_domain'] ?? '')));
        $new_domain = USDR_Replacer::normalize_domain(sanitize_text_field(wp_unslash($_POST['new_domain'] ?? '')));

        if ($brand === '' || $language === '') {
            return new WP_Error('missing_filter', __('Please select a brand and language.', 'us-domain-replacer'));
        }

        if ($old_domain === '' || $new_domain === '') {
            return new WP_Error('invalid_domain', __('Please provide valid old and new domains.', 'us-domain-replacer'));
        }

        if ($old_domain === $new_domain) {
            return new WP_Error('same_domain', __('Old and new domains must be different.', 'us-domain-replacer'));
        }

        $slugs = USDR_GSheet::get_slugs($brand, $language);
        if (is_wp_error($slugs)) {
            return $slugs;
        }

        if (empty($slugs)) {
            return new WP_Error(
                'no_slugs',
                __('No slugs were found in Google Sheets for the selected brand and language.', 'us-domain-replacer')
            );
        }

        return [
            'brand' => $brand,
            'language' => $language,
            'old_domain' => $old_domain,
            'new_domain' => $new_domain,
            'slugs' => $slugs,
        ];
    }

    /**
     * @return int[]
     */
    private static function request_link_ids() {
        $raw = wp_unslash($_POST['link_ids'] ?? []);
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $ids = array_map('absint', $raw);
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids;
    }
}
