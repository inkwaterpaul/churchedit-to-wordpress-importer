<?php
/**
 * Logger Class
 * Logs import activity to a dedicated table, same pattern as PI_Logger.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Logger {

    const TABLE_NAME = 'csi_import_log';

    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id bigint(20) UNSIGNED NOT NULL,
            source_ref varchar(64) NOT NULL,
            status varchar(50) NOT NULL,
            message text,
            user_id bigint(20) UNSIGNED NOT NULL,
            import_date datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY source_ref (source_ref),
            KEY import_date (import_date)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function log($post_id, $source_ref, $status = 'success', $message = '') {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE_NAME,
            array(
                'post_id'     => $post_id,
                'source_ref'  => $source_ref,
                'status'      => $status,
                'message'     => $message,
                'user_id'     => get_current_user_id(),
                'import_date' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s')
        );

        return $result === false ? false : $wpdb->insert_id;
    }

    public static function get_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return array(
            'total'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
            'success' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'success'"),
            'failed'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'failed'"),
        );
    }
}

register_activation_hook(CSI_PLUGIN_FILE, array('CSI_Logger', 'create_table'));
