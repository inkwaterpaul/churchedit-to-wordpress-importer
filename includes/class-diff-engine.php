<?php
/**
 * Diff Engine Class
 *
 * Compares rows from two ChurchEdit SQL exports (keyed by their source id —
 * page_id for `pages`, event_id for `calendar`) to find what's been added,
 * removed, or changed between them. Used by the "Compare & Update" admin
 * step so re-imports can be scoped to only what actually changed, instead of
 * blindly re-running a full import over every page/post/vacancy/event.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Diff_Engine {

    const PAGE_FIELDS = array('page_title', 'page_content', 'page_summary', 'page_access', 'folder_id');

    const EVENT_FIELDS = array(
        'short_event', 'long_event', 'summary',
        'year', 'month', 'day', 'hour', 'minute', 'FinishHour', 'FinishMinute',
        'AllDay', 'private', 'event_access', 'web_address', 'location_id', 'category_id',
    );

    /**
     * Long-text fields get a rendered wp_text_diff() table in render_item_diff();
     * everything else is treated as a short scalar (plain old -> new display).
     */
    const LONG_TEXT_FIELDS = array('page_content', 'page_summary', 'long_event', 'summary');

    /**
     * @param array $old_map Map of id => row, from the OLD export
     * @param array $new_map Map of id => row, from the NEW export
     * @param array $fields  Field names to compare when a row exists on both sides
     * @return array{added: array, removed: array, changed: array, unchanged: array}
     *   'changed' is [ id => ['fields' => [fieldname, ...]] ]; the rest are flat id lists.
     */
    public static function diff_rows($old_map, $new_map, $fields) {
        $added     = array();
        $removed   = array();
        $changed   = array();
        $unchanged = array();

        foreach ($new_map as $id => $new_row) {
            if (!isset($old_map[$id])) {
                $added[] = $id;
                continue;
            }

            $old_row = $old_map[$id];
            $changed_fields = array();
            foreach ($fields as $field) {
                $old_val = isset($old_row[$field]) ? (string) $old_row[$field] : '';
                $new_val = isset($new_row[$field]) ? (string) $new_row[$field] : '';
                if ($old_val !== $new_val) {
                    $changed_fields[] = $field;
                }
            }

            if (!empty($changed_fields)) {
                $changed[$id] = array('fields' => $changed_fields);
            } else {
                $unchanged[] = $id;
            }
        }

        foreach ($old_map as $id => $old_row) {
            if (!isset($new_map[$id])) {
                $removed[] = $id;
            }
        }

        return array(
            'added'     => $added,
            'removed'   => $removed,
            'changed'   => $changed,
            'unchanged' => $unchanged,
        );
    }

    /**
     * Build the per-field old/new/html breakdown for one changed item, for the
     * on-demand "View changes" panel. Only called for a single item at a time
     * (not for every changed row up front) since wp_text_diff() output can be
     * large.
     *
     * @return array List of ['field'=>, 'old'=>, 'new'=>, 'html'=>]
     */
    public static function render_item_diff($old_row, $new_row, $fields) {
        if (!function_exists('wp_text_diff')) {
            require_once ABSPATH . WPINC . '/wp-diff.php';
        }

        $rows = array();
        foreach ($fields as $field) {
            $old_val = isset($old_row[$field]) ? (string) $old_row[$field] : '';
            $new_val = isset($new_row[$field]) ? (string) $new_row[$field] : '';
            if ($old_val === $new_val) {
                continue;
            }

            if (in_array($field, self::LONG_TEXT_FIELDS, true)) {
                $html = wp_text_diff($old_val, $new_val, array('title' => '', 'show_split_view' => true));
                if ($html === false) {
                    $html = '<p><em>' . esc_html__('No visible differences.', 'churchedit-sql-importer') . '</em></p>';
                }
            } else {
                $html = '<p><del>' . esc_html($old_val) . '</del> &rarr; <ins>' . esc_html($new_val) . '</ins></p>';
            }

            $rows[] = array(
                'field' => $field,
                'old'   => $old_val,
                'new'   => $new_val,
                'html'  => $html,
            );
        }

        return $rows;
    }

    /**
     * Key an array of table rows by one of their columns, string-casting the
     * key (mysqldump ids parse as strings already, but callers may pass in
     * mixed types).
     */
    public static function key_by($rows, $key_field) {
        $map = array();
        foreach ($rows as $row) {
            if (isset($row[$key_field])) {
                $map[(string) $row[$key_field]] = $row;
            }
        }
        return $map;
    }
}
