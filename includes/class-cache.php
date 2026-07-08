<?php
/**
 * Cache Class
 * Stores the parsed+resolved dataset on disk between AJAX requests (it's too
 * large for a single transient) so batched import calls stay stateless and fast.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Cache {

    public static function dir() {
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . '/csi-cache';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }
        return $dir;
    }

    public static function load($cache_key) {
        $cache_key = preg_replace('/[^a-f0-9]/', '', (string) $cache_key);
        if (empty($cache_key)) {
            return null;
        }
        $path = self::dir() . '/' . $cache_key . '.json';
        if (!file_exists($path)) {
            return null;
        }
        $json = file_get_contents($path);
        return json_decode($json, true);
    }
}
