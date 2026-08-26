<?php
/**
 * Plugin Name: Movie Meta
 * Description: Loads movie and series catalog from Google Sheets and exposes them as JSON for frontend display.
 * Version: 1.9.2
 * Author: Aris
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: movie-meta-by-aris
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MMBA_VERSION', '1.9.2');
define('MMBA_PLUGIN_FILE', __FILE__);
define('MMBA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MMBA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once MMBA_PLUGIN_DIR . 'includes/class-storage.php';
require_once MMBA_PLUGIN_DIR . 'includes/class-sheets.php';
require_once MMBA_PLUGIN_DIR . 'includes/class-admin.php';
require_once MMBA_PLUGIN_DIR . 'includes/class-api.php';
require_once MMBA_PLUGIN_DIR . 'includes/class-shortcode.php';

register_activation_hook(__FILE__, ['MMBA_Storage', 'activate']);
register_deactivation_hook(__FILE__, ['MMBA_Storage', 'deactivate']);

add_action('plugins_loaded', static function () {
    MMBA_Storage::init();
    MMBA_Sheets::init();
    MMBA_Admin::init();
    MMBA_API::init();
    MMBA_Shortcode::init();
});
