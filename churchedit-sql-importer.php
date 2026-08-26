<?php
/**
 * Plugin Name: ChurchEdit SQL Importer
 * Plugin URI: https://inkandwater.co.uk/churchedit-sql-importer
 * Description: Imports a ChurchEdit CMS SQL export into WordPress pages, reconstructing the page hierarchy from ChurchEdit's folders table and converting content to Gutenberg blocks.
 * Version: 1.0.3
 * Author: Ink & Water
 * Author URI: https://inkandwater.co.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: churchedit-sql-importer
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

define('CSI_VERSION', '1.0.2');
define('CSI_NAME', 'ChurchEdit SQL Importer');
define('CSI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CSI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CSI_PLUGIN_FILE', __FILE__);

require_once CSI_PLUGIN_DIR . 'includes/class-logger.php';
require_once CSI_PLUGIN_DIR . 'includes/class-cache.php';
require_once CSI_PLUGIN_DIR . 'includes/class-sql-dump-parser.php';
require_once CSI_PLUGIN_DIR . 'includes/class-diff-engine.php';
require_once CSI_PLUGIN_DIR . 'includes/class-hierarchy-resolver.php';
require_once CSI_PLUGIN_DIR . 'includes/class-content-converter.php';
require_once CSI_PLUGIN_DIR . 'includes/class-page-links-extractor.php';
require_once CSI_PLUGIN_DIR . 'includes/class-page-links-resolver.php';
require_once CSI_PLUGIN_DIR . 'includes/class-importer.php';
require_once CSI_PLUGIN_DIR . 'includes/class-post-importer.php';
require_once CSI_PLUGIN_DIR . 'includes/class-calendar-importer.php';
require_once CSI_PLUGIN_DIR . 'includes/class-vacancy-importer.php';
require_once CSI_PLUGIN_DIR . 'includes/class-member-importer.php';
require_once CSI_PLUGIN_DIR . 'includes/class-media-linker.php';
require_once CSI_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once CSI_PLUGIN_DIR . 'includes/class-admin-ui.php';

class CSI_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        CSI_AJAX_Handler::init();
    }

    public function add_admin_menu() {
        add_menu_page(
            __('ChurchEdit Importer', 'churchedit-sql-importer'),
            __('ChurchEdit Importer', 'churchedit-sql-importer'),
            'manage_options',
            'churchedit-sql-importer',
            array('CSI_Admin_UI', 'render_page'),
            'dashicons-migrate',
            31
        );
    }

    public function enqueue_admin_assets($hook) {
        if ('toplevel_page_churchedit-sql-importer' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'csi-admin-style',
            CSI_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            CSI_VERSION
        );

        wp_enqueue_script(
            'csi-admin-script',
            CSI_PLUGIN_URL . 'assets/js/admin-script.js',
            array('jquery'),
            CSI_VERSION,
            true
        );

        wp_localize_script('csi-admin-script', 'csiAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('csi_nonce'),
        ));
    }
}

function csi_init() {
    return CSI_Plugin::get_instance();
}
add_action('plugins_loaded', 'csi_init');
