<?php
/**
 * Plugin Name: URL Shortify Domain Replacer
 * Description: Bulk replace old domains with new domains in URL Shortify target URLs. Keeps slugs and paths unchanged.
 * Version: 1.0.1
 * Author: Custom
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: us-domain-replacer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('USDR_VERSION', '1.0.1');
define('USDR_PLUGIN_FILE', __FILE__);
define('USDR_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once USDR_PLUGIN_DIR . 'includes/class-replacer.php';
require_once USDR_PLUGIN_DIR . 'includes/class-admin.php';

add_action('plugins_loaded', static function () {
    if (!class_exists('KaizenCoders\URL_Shortify\Helper')) {
        add_action('admin_notices', static function () {
            if (!current_user_can('manage_options')) {
                return;
            }
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('URL Shortify Domain Replacer requires the URL Shortify plugin to be active.', 'us-domain-replacer');
            echo '</p></div>';
        });
        return;
    }

    USDR_Admin::init();
});
