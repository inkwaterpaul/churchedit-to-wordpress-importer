<?php
/**
 * AJAX Handler Class
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_AJAX_Handler {

    public static function init() {
        add_action('wp_ajax_csi_upload_sql_zip', array(__CLASS__, 'handle_upload_sql_zip'));
        add_action('wp_ajax_csi_parse_sql', array(__CLASS__, 'handle_parse'));
        add_action('wp_ajax_csi_import_batch', array(__CLASS__, 'handle_import_batch'));
        add_action('wp_ajax_csi_link_media_batch', array(__CLASS__, 'handle_link_media_batch'));
        add_action('wp_ajax_csi_parse_members', array(__CLASS__, 'handle_parse_members'));
        add_action('wp_ajax_csi_import_members_batch', array(__CLASS__, 'handle_import_members_batch'));
        add_action('wp_ajax_csi_import_posts_batch', array(__CLASS__, 'handle_import_posts_batch'));
        add_action('wp_ajax_csi_parse_calendar', array(__CLASS__, 'handle_parse_calendar'));
        add_action('wp_ajax_csi_import_calendar_batch', array(__CLASS__, 'handle_import_calendar_batch'));
        add_action('wp_ajax_csi_import_vacancies_batch', array(__CLASS__, 'handle_import_vacancies_batch'));
        add_action('wp_ajax_csi_resolve_page_links_batch', array(__CLASS__, 'handle_resolve_page_links_batch'));
        add_action('wp_ajax_csi_compare_pages', array(__CLASS__, 'handle_compare_pages'));
        add_action('wp_ajax_csi_compare_calendar', array(__CLASS__, 'handle_compare_calendar'));
        add_action('wp_ajax_csi_diff_view_item', array(__CLASS__, 'handle_diff_view_item'));
    }

    /**
     * Compare an OLD export's `pages` table against the already-parsed NEW
     * dataset (cached by step 1), bucketed into Pages-tree / Posts-folder /
     * Vacancies-folder using the exact same folder data those steps use —
     * so "what counts as a vacancy" here never drifts from what the Import
     * Vacancies step would actually import.
     */
    public static function handle_compare_pages() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $cache_key = isset($_POST['cache_key']) ? sanitize_text_field($_POST['cache_key']) : '';
            $resolved  = CSI_Cache::load($cache_key);
            if (!$resolved) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Parsed data not found — please re-run the parse step (1) on the new export first.', 'churchedit-sql-importer')));
            }

            $old_file_path = isset($_POST['old_file_path']) ? wp_unslash($_POST['old_file_path']) : '';
            if (empty($old_file_path) || !file_exists($old_file_path)) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Old export file not found at that path.', 'churchedit-sql-importer')));
            }

            $tables = CSI_SQL_Dump_Parser::parse_tables($old_file_path, array('pages'));
            if (is_wp_error($tables)) {
                ob_end_clean();
                wp_send_json_error(array('message' => $tables->get_error_message()));
            }

            $old_pages = CSI_Diff_Engine::key_by($tables['pages'], 'page_id');

            $old_cache_key = md5($old_file_path . '|' . filemtime($old_file_path) . '|pages_raw');
            file_put_contents(CSI_Cache::dir() . '/' . $old_cache_key . '.json', wp_json_encode($old_pages));

            $diff = CSI_Diff_Engine::diff_rows($old_pages, $resolved['pages'], CSI_Diff_Engine::PAGE_FIELDS);

            $pages_tree_ids = array();
            foreach ($resolved['folders'] as $folder) {
                if ($folder['excluded']) {
                    continue;
                }
                foreach ($folder['page_ids'] as $pid) {
                    $pages_tree_ids[$pid] = true;
                }
            }

            $posts_folder_id = isset($_POST['posts_folder_id']) ? sanitize_text_field($_POST['posts_folder_id']) : '';
            $posts_ids = array();
            if ($posts_folder_id !== '' && isset($resolved['folders'][$posts_folder_id])) {
                foreach ($resolved['folders'][$posts_folder_id]['page_ids'] as $pid) {
                    $posts_ids[$pid] = true;
                }
            }

            $vacancies_folder_id = isset($_POST['vacancies_folder_id']) ? sanitize_text_field($_POST['vacancies_folder_id']) : '';
            $vacancies_ids = array();
            if ($vacancies_folder_id !== '' && isset($resolved['folders'][$vacancies_folder_id])) {
                foreach (CSI_Vacancy_Importer::get_vacancy_items($resolved, $vacancies_folder_id) as $item) {
                    $vacancies_ids[$item['page_id']] = true;
                }
            }

            $bucketed_ids = $pages_tree_ids + $posts_ids + $vacancies_ids;

            $buckets = array(
                'pages'     => self::bucket_page_diff($diff, $pages_tree_ids, $resolved['pages'], $old_pages, 'page'),
                'posts'     => self::bucket_page_diff($diff, $posts_ids, $resolved['pages'], $old_pages, 'post'),
                'vacancies' => self::bucket_page_diff($diff, $vacancies_ids, $resolved['pages'], $old_pages, 'vacancy'),
            );

            $other_changed = 0;
            foreach ($diff['changed'] as $id => $info) {
                if (!isset($bucketed_ids[$id])) {
                    $other_changed++;
                }
            }

            ob_end_clean();
            wp_send_json_success(array(
                'old_pages_cache_key' => $old_cache_key,
                'buckets'             => $buckets,
                'other_changed'       => $other_changed,
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Filter a full pages diff() result down to one bucket's id set and shape
     * it for the UI: counts + a changed-item list with ref/title/changed
     * fields (title/ref for removed items comes from the OLD row, since
     * there's no NEW row to read it from).
     */
    private static function bucket_page_diff($diff, $id_set, $new_pages, $old_pages, $ref_prefix) {
        $changed = array();
        foreach ($diff['changed'] as $id => $info) {
            if (!isset($id_set[$id])) {
                continue;
            }
            $changed[] = array(
                'ref'    => $ref_prefix . ':' . $id,
                'id'     => $id,
                'title'  => $new_pages[$id]['page_title'],
                'fields' => $info['fields'],
            );
        }

        $added = array();
        foreach ($diff['added'] as $id) {
            if (isset($id_set[$id])) {
                $added[] = array('ref' => $ref_prefix . ':' . $id, 'id' => $id, 'title' => $new_pages[$id]['page_title']);
            }
        }

        $removed = array();
        foreach ($diff['removed'] as $id) {
            if (isset($id_set[$id])) {
                $removed[] = array('id' => $id, 'title' => $old_pages[$id]['page_title']);
            }
        }

        $unchanged_count = 0;
        foreach ($diff['unchanged'] as $id) {
            if (isset($id_set[$id])) {
                $unchanged_count++;
            }
        }

        return array(
            'added'           => $added,
            'changed'         => $changed,
            'removed'         => $removed,
            'unchanged_count' => $unchanged_count,
        );
    }

    /**
     * Compare an OLD export's `calendar` table against the already-parsed
     * NEW calendar cache (from step 5).
     */
    public static function handle_compare_calendar() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $cache_key = isset($_POST['cache_key']) ? sanitize_text_field($_POST['cache_key']) : '';
            $new_data  = CSI_Cache::load($cache_key);
            if (!$new_data) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Parsed calendar data not found — please run "Scan Calendar" (step 5) on the new export first.', 'churchedit-sql-importer')));
            }

            $old_file_path = isset($_POST['old_file_path']) ? wp_unslash($_POST['old_file_path']) : '';
            if (empty($old_file_path) || !file_exists($old_file_path)) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Old export file not found at that path.', 'churchedit-sql-importer')));
            }

            $tables = CSI_SQL_Dump_Parser::parse_tables($old_file_path, array('calendar'));
            if (is_wp_error($tables)) {
                ob_end_clean();
                wp_send_json_error(array('message' => $tables->get_error_message()));
            }

            $old_events = CSI_Diff_Engine::key_by($tables['calendar'], 'event_id');
            $new_events = CSI_Diff_Engine::key_by($new_data['events'], 'event_id');

            $old_cache_key = md5($old_file_path . '|' . filemtime($old_file_path) . '|events_raw');
            file_put_contents(CSI_Cache::dir() . '/' . $old_cache_key . '.json', wp_json_encode($old_events));

            $diff = CSI_Diff_Engine::diff_rows($old_events, $new_events, CSI_Diff_Engine::EVENT_FIELDS);

            $changed = array();
            foreach ($diff['changed'] as $id => $info) {
                $changed[] = array(
                    'ref'    => 'event:' . $id,
                    'id'     => $id,
                    'title'  => $new_events[$id]['short_event'],
                    'fields' => $info['fields'],
                );
            }

            $added = array();
            foreach ($diff['added'] as $id) {
                $added[] = array('ref' => 'event:' . $id, 'id' => $id, 'title' => $new_events[$id]['short_event']);
            }

            $removed = array();
            foreach ($diff['removed'] as $id) {
                $removed[] = array('id' => $id, 'title' => $old_events[$id]['short_event']);
            }

            ob_end_clean();
            wp_send_json_success(array(
                'old_events_cache_key' => $old_cache_key,
                'added'                => $added,
                'changed'              => $changed,
                'removed'              => $removed,
                'unchanged_count'      => count($diff['unchanged']),
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * On-demand field-level diff for a single changed page or event — kept
     * out of the bulk compare response since wp_text_diff() output can be
     * large and most changed items are never expanded.
     */
    public static function handle_diff_view_item() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $kind = isset($_POST['kind']) ? sanitize_key($_POST['kind']) : '';
            $id   = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
            $old_cache_key = isset($_POST['old_cache_key']) ? sanitize_text_field($_POST['old_cache_key']) : '';
            $cache_key     = isset($_POST['cache_key']) ? sanitize_text_field($_POST['cache_key']) : '';

            $old_map = CSI_Cache::load($old_cache_key);
            if (!$old_map || !isset($old_map[$id])) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Old item data not found — please re-run Compare.', 'churchedit-sql-importer')));
            }

            if ($kind === 'event') {
                $new_data = CSI_Cache::load($cache_key);
                if (!$new_data) {
                    ob_end_clean();
                    wp_send_json_error(array('message' => __('New calendar data not found — please re-run Compare.', 'churchedit-sql-importer')));
                }
                $new_map = CSI_Diff_Engine::key_by($new_data['events'], 'event_id');
                $fields  = CSI_Diff_Engine::EVENT_FIELDS;
            } else {
                $resolved = CSI_Cache::load($cache_key);
                if (!$resolved) {
                    ob_end_clean();
                    wp_send_json_error(array('message' => __('New pages data not found — please re-run Compare.', 'churchedit-sql-importer')));
                }
                $new_map = $resolved['pages'];
                $fields  = CSI_Diff_Engine::PAGE_FIELDS;
            }

            if (!isset($new_map[$id])) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('New item data not found.', 'churchedit-sql-importer')));
            }

            $rows = CSI_Diff_Engine::render_item_diff($old_map[$id], $new_map[$id], $fields);

            ob_end_clean();
            wp_send_json_success(array('rows' => $rows));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Resolve one batch of "flexContainer" page-link card grids extracted
     * during the page import into real octopus/page-links blocks now that
     * their linked pages (hopefully) have WP post IDs.
     */
    public static function handle_resolve_page_links_batch() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $batch_size  = isset($_POST['batch_size']) ? max(1, absint($_POST['batch_size'])) : 10;
            $exclude_ids = isset($_POST['exclude_ids']) ? array_map('absint', (array) $_POST['exclude_ids']) : array();

            @set_time_limit(120);
            $results = CSI_Page_Links_Resolver::resolve_batch($batch_size, $exclude_ids);

            ob_end_clean();
            wp_send_json_success(array(
                'results' => $results,
                'done'    => ($results['batch_count'] === 0),
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Import one batch of a vacancy-root folder's pages as 'vacancy' posts,
     * using the same cached resolved dataset the Pages tree parse step built.
     */
    public static function handle_import_vacancies_batch() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $cache_key = isset($_POST['cache_key']) ? sanitize_text_field($_POST['cache_key']) : '';
            $resolved  = CSI_Cache::load($cache_key);
            if (!$resolved) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Parsed data not found — please re-run the parse step.', 'churchedit-sql-importer')));
            }

            $folder_id = isset($_POST['folder_id']) ? sanitize_text_field($_POST['folder_id']) : '';
            if (!isset($resolved['folders'][$folder_id])) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Unknown folder.', 'churchedit-sql-importer')));
            }

            $items = CSI_Vacancy_Importer::get_vacancy_items($resolved, $folder_id);

            // Targeted-update pass from Compare & Update: restrict to only
            // the page_ids the user selected there instead of the whole folder.
            $only_page_ids = isset($_POST['only_page_ids']) ? array_map('sanitize_text_field', (array) $_POST['only_page_ids']) : array();
            if (!empty($only_page_ids)) {
                $only_page_ids = array_flip($only_page_ids);
                $items = array_values(array_filter($items, function ($item) use ($only_page_ids) {
                    return isset($only_page_ids[$item['page_id']]);
                }));
            }

            $offset     = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
            $batch_size = isset($_POST['batch_size']) ? max(1, absint($_POST['batch_size'])) : 20;
            $total      = count($items);
            $slice      = array_slice($items, $offset, $batch_size);

            $options = array(
                'default_status' => (isset($_POST['default_status']) && in_array($_POST['default_status'], array('draft', 'publish', 'pending'), true)) ? $_POST['default_status'] : 'draft',
            );

            @set_time_limit(120);

            $results = array();
            foreach ($slice as $item) {
                error_reporting(E_ERROR);
                $results[] = CSI_Vacancy_Importer::import_vacancy($resolved['pages'][$item['page_id']], $item['category'], $options);
                error_reporting(E_ALL);
            }

            ob_end_clean();
            wp_send_json_success(array(
                'results'     => $results,
                'next_offset' => $offset + count($slice),
                'total'       => $total,
                'done'        => ($offset + count($slice)) >= $total,
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Import one batch of a single folder's pages as flat WP Posts, using the
     * same cached resolved dataset the Pages tree parse step already built —
     * no separate parse needed since `pages` was already fully parsed then.
     */
    public static function handle_import_posts_batch() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $cache_key = isset($_POST['cache_key']) ? sanitize_text_field($_POST['cache_key']) : '';
            $resolved  = CSI_Cache::load($cache_key);
            if (!$resolved) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Parsed data not found — please re-run the parse step.', 'churchedit-sql-importer')));
            }

            $folder_id = isset($_POST['folder_id']) ? sanitize_text_field($_POST['folder_id']) : '';
            if (!isset($resolved['folders'][$folder_id])) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Unknown folder.', 'churchedit-sql-importer')));
            }

            $page_ids = $resolved['folders'][$folder_id]['page_ids'];

            // Targeted-update pass from Compare & Update: restrict to only the
            // page_ids the user selected there instead of the whole folder.
            $only_page_ids = isset($_POST['only_page_ids']) ? array_map('sanitize_text_field', (array) $_POST['only_page_ids']) : array();
            if (!empty($only_page_ids)) {
                $only_page_ids = array_flip($only_page_ids);
                $page_ids = array_values(array_filter($page_ids, function ($pid) use ($only_page_ids) {
                    return isset($only_page_ids[$pid]);
                }));
            }

            $offset     = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
            $batch_size = isset($_POST['batch_size']) ? max(1, absint($_POST['batch_size'])) : 20;
            $total      = count($page_ids);
            $slice      = array_slice($page_ids, $offset, $batch_size);

            $options = array(
                'default_status' => (isset($_POST['default_status']) && in_array($_POST['default_status'], array('draft', 'publish', 'pending'), true)) ? $_POST['default_status'] : 'draft',
            );

            @set_time_limit(120);

            $results = array();
            foreach ($slice as $page_id) {
                error_reporting(E_ERROR);
                $results[] = CSI_Post_Importer::import_post($resolved['pages'][$page_id], $options);
                error_reporting(E_ALL);
            }

            ob_end_clean();
            wp_send_json_success(array(
                'results'     => $results,
                'next_offset' => $offset + count($slice),
                'total'       => $total,
                'done'        => ($offset + count($slice)) >= $total,
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Parse `calendar` + `calendar_locations` from the same SQL file and cache them.
     */
    public static function handle_parse_calendar() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            if (!class_exists('Tribe__Events__Main')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('The Events Calendar plugin is not active on this site.', 'churchedit-sql-importer')));
            }

            $file_path = isset($_POST['file_path']) ? wp_unslash($_POST['file_path']) : '';
            if (empty($file_path) || !file_exists($file_path)) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('File not found at that path.', 'churchedit-sql-importer')));
            }

            $tables = CSI_SQL_Dump_Parser::parse_tables($file_path, array('calendar', 'calendar_locations'));
            if (is_wp_error($tables)) {
                ob_end_clean();
                wp_send_json_error(array('message' => $tables->get_error_message()));
            }

            $locations = array();
            foreach ($tables['calendar_locations'] as $loc) {
                $locations[$loc['id']] = $loc;
            }

            $cache_key = md5($file_path . '|calendar');
            file_put_contents(CSI_Cache::dir() . '/' . $cache_key . '.json', wp_json_encode(array(
                'events'    => $tables['calendar'],
                'locations' => $locations,
            )));

            ob_end_clean();
            wp_send_json_success(array(
                'cache_key' => $cache_key,
                'total'     => count($tables['calendar']),
                'locations' => count($locations),
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    public static function handle_import_calendar_batch() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $cache_key = isset($_POST['cache_key']) ? sanitize_text_field($_POST['cache_key']) : '';
            $data = CSI_Cache::load($cache_key);
            if (!$data) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Parsed calendar data not found — please re-run parse.', 'churchedit-sql-importer')));
            }

            $events = $data['events'];

            // Targeted-update pass from Compare & Update: restrict to only the
            // event_ids the user selected there, and skip the from-date filter
            // since those events were already picked explicitly.
            $only_event_ids = isset($_POST['only_event_ids']) ? array_map('sanitize_text_field', (array) $_POST['only_event_ids']) : array();
            if (!empty($only_event_ids)) {
                $only_event_ids = array_flip($only_event_ids);
                $events = array_values(array_filter($events, function ($event) use ($only_event_ids) {
                    return isset($only_event_ids[$event['event_id']]);
                }));
            } else {
                $from_date = isset($_POST['from_date']) ? sanitize_text_field($_POST['from_date']) : '';
                if ($from_date !== '') {
                    $events = CSI_Calendar_Importer::filter_from_date($events, $from_date);
                }
            }

            $locations  = $data['locations'];
            $offset     = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
            $batch_size = isset($_POST['batch_size']) ? max(1, absint($_POST['batch_size'])) : 10;
            $total      = count($events);
            $slice      = array_slice($events, $offset, $batch_size);

            $options = array(
                'default_status' => (isset($_POST['default_status']) && in_array($_POST['default_status'], array('draft', 'publish', 'pending'), true)) ? $_POST['default_status'] : 'draft',
            );

            @set_time_limit(120);

            $results = array();
            foreach ($slice as $event) {
                error_reporting(E_ERROR);
                $results[] = CSI_Calendar_Importer::import_event($event, $locations, $options);
                error_reporting(E_ALL);
            }

            ob_end_clean();
            wp_send_json_success(array(
                'results'     => $results,
                'next_offset' => $offset + count($slice),
                'total'       => $total,
                'done'        => ($offset + count($slice)) >= $total,
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Accept a ZIP containing the ChurchEdit .sql export, extract it server-side
     * (same pattern page-importer-from-html uses for its folder-import ZIP),
     * and return the extracted .sql file's server path so the existing
     * path-based parse step can run unchanged. Extraction target is keyed by
     * the uploaded file's name+size, so re-uploading the same ZIP during
     * testing reuses the extracted copy instead of re-extracting 100MB+ every time.
     */
    public static function handle_upload_sql_zip() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            if (empty($_FILES['sql_zip'])) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('No file was uploaded.', 'churchedit-sql-importer')));
            }

            $file = $_FILES['sql_zip'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $message = self::upload_error_message($file['error']);
                ob_end_clean();
                wp_send_json_error(array('message' => $message));
            }

            if (!class_exists('ZipArchive')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('ZipArchive is not available on this server. Please contact your host.', 'churchedit-sql-importer')));
            }

            $key = md5(sanitize_file_name($file['name']) . '|' . $file['size']);
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/csi-temp/' . $key;

            $existing_sql = self::find_sql_file($target_dir);
            if ($existing_sql) {
                ob_end_clean();
                wp_send_json_success(array('file_path' => $existing_sql, 'reused' => true));
            }

            wp_mkdir_p($target_dir);

            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== true) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Could not open ZIP file. Please ensure it is a valid ZIP.', 'churchedit-sql-importer')));
            }

            @set_time_limit(300);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (substr($name, -1) === '/' || basename($name)[0] === '.') {
                    continue;
                }
                $rel = preg_replace('/\.\.+[\/\\\\]/', '', $name);
                $rel = ltrim($rel, '/\\');
                if (empty($rel)) {
                    continue;
                }
                $target_path = $target_dir . '/' . $rel;
                wp_mkdir_p(dirname($target_path));

                $fp_in = $zip->getStream($name);
                if ($fp_in === false) {
                    continue;
                }
                $fp_out = fopen($target_path, 'wb');
                if ($fp_out === false) {
                    fclose($fp_in);
                    continue;
                }
                stream_copy_to_stream($fp_in, $fp_out);
                fclose($fp_in);
                fclose($fp_out);
            }
            $zip->close();

            $sql_path = self::find_sql_file($target_dir);
            if (!$sql_path) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('No .sql file found inside the ZIP.', 'churchedit-sql-importer')));
            }

            ob_end_clean();
            wp_send_json_success(array('file_path' => $sql_path, 'reused' => false));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    private static function find_sql_file($dir) {
        if (!is_dir($dir)) {
            return null;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (strtolower($file->getExtension()) === 'sql') {
                return $file->getPathname();
            }
        }
        return null;
    }

    private static function upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return sprintf(
                    __('The ZIP is larger than this server allows (upload_max_filesize=%s, post_max_size=%s). Raise both in your PHP settings and try again.', 'churchedit-sql-importer'),
                    ini_get('upload_max_filesize'),
                    ini_get('post_max_size')
                );
            case UPLOAD_ERR_PARTIAL:
                return __('The upload was interrupted partway through. Please try again.', 'churchedit-sql-importer');
            case UPLOAD_ERR_NO_FILE:
                return __('No file was uploaded.', 'churchedit-sql-importer');
            default:
                return sprintf(__('Upload failed (error code %d).', 'churchedit-sql-importer'), $error_code);
        }
    }

    /**
     * Parse the `members` table from the same .sql file, filter to live
     * accounts, cache to disk, and return a summary + preview for the UI.
     */
    public static function handle_parse_members() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $file_path = isset($_POST['file_path']) ? wp_unslash($_POST['file_path']) : '';
            if (empty($file_path) || !file_exists($file_path)) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('File not found at that path.', 'churchedit-sql-importer')));
            }

            $tables = CSI_SQL_Dump_Parser::parse_tables($file_path, array('members'));
            if (is_wp_error($tables)) {
                ob_end_clean();
                wp_send_json_error(array('message' => $tables->get_error_message()));
            }

            $live = CSI_Member_Importer::filter_live($tables['members']);

            $cache_key = md5($file_path . '|members');
            file_put_contents(CSI_Cache::dir() . '/' . $cache_key . '.json', wp_json_encode($live));

            $admin_tier = count(array_filter($live, function ($m) { return $m['master_access'] === 'Y'; }));

            ob_end_clean();
            wp_send_json_success(array(
                'cache_key' => $cache_key,
                'total'     => count($tables['members']),
                'live'      => count($live),
                'admin_tier' => $admin_tier,
                'default_tier' => count($live) - $admin_tier,
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    public static function handle_import_members_batch() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $cache_key = isset($_POST['cache_key']) ? sanitize_text_field($_POST['cache_key']) : '';
            $members = CSI_Cache::load($cache_key);
            if (!$members) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Parsed member data not found — please re-run parse.', 'churchedit-sql-importer')));
            }

            $offset     = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
            $batch_size = isset($_POST['batch_size']) ? max(1, absint($_POST['batch_size'])) : 20;
            $total      = count($members);
            $slice      = array_slice($members, $offset, $batch_size);

            @set_time_limit(120);

            $results = array();
            foreach ($slice as $member) {
                $results[] = CSI_Member_Importer::import_member($member);
            }

            ob_end_clean();
            wp_send_json_success(array(
                'results'     => $results,
                'next_offset' => $offset + count($slice),
                'total'       => $total,
                'done'        => ($offset + count($slice)) >= $total,
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Step 1: parse the .sql file at the given server path, resolve hierarchy,
     * cache the full resolved dataset to disk, and return a lightweight tree
     * for the picker UI.
     */
    public static function handle_parse() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $file_path = isset($_POST['file_path']) ? wp_unslash($_POST['file_path']) : '';
            if (empty($file_path) || !file_exists($file_path)) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('File not found at that path.', 'churchedit-sql-importer')));
            }

            $tables = CSI_SQL_Dump_Parser::parse_tables($file_path, array('folders', 'pages'));
            if (is_wp_error($tables)) {
                ob_end_clean();
                wp_send_json_error(array('message' => $tables->get_error_message()));
            }

            $resolved = CSI_Hierarchy_Resolver::build($tables['folders'], $tables['pages']);
            // Computed once here (not per import batch) since it's the same
            // for the whole run and building it needs the full folders/pages
            // structure that only this cache already holds.
            $resolved['url_index'] = CSI_Hierarchy_Resolver::build_url_index($resolved);

            $cache_key = md5($file_path . '|' . filemtime($file_path));
            $cache_dir = CSI_Cache::dir();
            $cache_path = $cache_dir . '/' . $cache_key . '.json';
            file_put_contents($cache_path, wp_json_encode($resolved));

            $tree = self::build_ui_tree($resolved);

            $summary = array(
                'folders'          => count($resolved['folders']),
                'pages'            => count($resolved['pages']),
                'excluded_folders' => count(array_filter($resolved['folders'], function ($f) { return $f['excluded']; })),
                'draft_pages'      => count(array_filter($resolved['pages'], function ($p) { return $p['force_draft']; })),
                'card_auto'        => count(array_filter($resolved['pages'], function ($p) { return $p['card_auto']; })),
                'card_review'      => count(array_filter($resolved['pages'], function ($p) { return $p['card_review']; })),
            );

            $large_folders = array();
            foreach ($resolved['folders'] as $fid => $f) {
                if (!empty($f['large_folder'])) {
                    $large_folders[] = array(
                        'folder_id'  => $fid,
                        'title'      => $f['folder_name'] ? $f['folder_name'] : $f['folder_full_name'],
                        'page_count' => count($f['page_ids']),
                    );
                }
            }

            $vacancy_folders = array();
            foreach ($resolved['vacancy_root_folder_ids'] as $fid) {
                $f = $resolved['folders'][$fid];
                $item_count = count(CSI_Vacancy_Importer::get_vacancy_items($resolved, $fid));
                $vacancy_folders[] = array(
                    'folder_id'  => $fid,
                    'title'      => $f['folder_name'] ? trim($f['folder_name']) : $f['folder_full_name'],
                    'item_count' => $item_count,
                );
            }

            ob_end_clean();
            wp_send_json_success(array(
                'cache_key'       => $cache_key,
                'summary'         => $summary,
                'tree'            => $tree,
                'large_folders'   => $large_folders,
                'vacancy_folders' => $vacancy_folders,
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Step 2: import one batch of the selected tree. The client tracks offset;
     * the server recomputes the deterministic parent-first order each call from
     * the cached resolved data and slices it, so batches stay stateless.
     */
    public static function handle_import_batch() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $cache_key = isset($_POST['cache_key']) ? sanitize_text_field($_POST['cache_key']) : '';
            $resolved  = CSI_Cache::load($cache_key);
            if (!$resolved) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Parsed data not found — please re-run the parse step.', 'churchedit-sql-importer')));
            }

            $selected_folder_ids = isset($_POST['folder_ids']) ? array_map('sanitize_text_field', (array) $_POST['folder_ids']) : array();
            $selected_page_ids   = isset($_POST['page_ids']) ? array_map('sanitize_text_field', (array) $_POST['page_ids']) : array();
            $offset     = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
            $batch_size = isset($_POST['batch_size']) ? max(1, absint($_POST['batch_size'])) : 20;

            $options = array(
                'default_status' => (isset($_POST['default_status']) && in_array($_POST['default_status'], array('draft', 'publish', 'pending'), true)) ? $_POST['default_status'] : 'draft',
                'block_pattern'  => isset($_POST['block_pattern']) ? wp_unslash($_POST['block_pattern']) : '',
                'url_index'      => isset($resolved['url_index']) ? $resolved['url_index'] : array('pages' => array(), 'folders' => array()),
            );

            // A targeted-update pass (from the Compare & Update step) posts an
            // explicit list of refs to update rather than a folder/page tree
            // selection — parent-first ordering isn't needed there since
            // those refs' parents already exist as WP posts from the
            // original import.
            $only_refs = isset($_POST['refs']) ? array_map('sanitize_text_field', (array) $_POST['refs']) : array();
            if (!empty($only_refs)) {
                $order = array_values(array_unique($only_refs));
            } else {
                $order = CSI_Importer::get_import_order($resolved, $selected_folder_ids, $selected_page_ids);
            }
            $total = count($order);
            $slice = array_slice($order, $offset, $batch_size);

            @set_time_limit(120);

            $results = array();
            foreach ($slice as $ref) {
                error_reporting(E_ERROR);
                $results[] = CSI_Importer::import_item($ref, $resolved, $options);
                error_reporting(E_ALL);
            }

            ob_end_clean();
            wp_send_json_success(array(
                'results'    => $results,
                'next_offset' => $offset + count($slice),
                'total'      => $total,
                'done'       => ($offset + count($slice)) >= $total,
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Pure media-library lookup, no uploading — images/documents are
     * uploaded independently of this plugin (plain WordPress uploads). Scoped
     * to a single post type per call, so each of the admin UI's per-type
     * buttons only ever queries/processes that one type.
     */
    public static function handle_link_media_batch() {
        ob_start();
        try {
            check_ajax_referer('csi_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Permission denied.', 'churchedit-sql-importer')));
            }

            $allowed_types = array('page', 'post', 'vacancy', 'tribe_events');
            $post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '';
            if (!in_array($post_type, $allowed_types, true)) {
                ob_end_clean();
                wp_send_json_error(array('message' => __('Unknown or missing post type.', 'churchedit-sql-importer')));
            }

            $batch_size  = isset($_POST['batch_size']) ? max(1, absint($_POST['batch_size'])) : 10;
            $exclude_ids = isset($_POST['exclude_ids']) ? array_map('absint', (array) $_POST['exclude_ids']) : array();

            @set_time_limit(120);
            $results = CSI_Media_Linker::link_batch($post_type, $batch_size, $exclude_ids);

            ob_end_clean();
            wp_send_json_success(array(
                'results' => $results,
                'done'    => ($results['batch_count'] === 0),
            ));
        } catch (Exception $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        } catch (Error $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
        }
    }

    /**
     * Build a lightweight nested tree (no page_content) for the picker UI.
     */
    private static function build_ui_tree($resolved) {
        $build_folder = function ($folder_id) use (&$build_folder, $resolved) {
            $folder = $resolved['folders'][$folder_id];

            $node = array(
                'kind'           => 'folder',
                'folder_id'      => $folder_id,
                'title'          => $folder['folder_name'] ? $folder['folder_name'] : $folder['folder_full_name'],
                'excluded'       => $folder['excluded'],
                'exclude_reason' => $folder['exclude_reason'],
                'large_folder'   => $folder['large_folder'],
                'page_count'     => count($folder['page_ids']),
                'node_ref'       => $folder['excluded'] ? null : $folder['node_ref'],
                'children'       => array(),
            );

            if ($folder['excluded']) {
                return $node;
            }

            if (strpos($folder['node_ref'], 'page:') === 0) {
                $node_page_id = substr($folder['node_ref'], 5);
                $node['node_title'] = $resolved['pages'][$node_page_id]['page_title'];
            } else {
                $node['node_title'] = null; // stub — no source page
            }

            foreach ($folder['page_ids'] as $page_id) {
                $page = $resolved['pages'][$page_id];
                if ($page['is_folder_node']) {
                    continue;
                }
                $node['children'][] = array(
                    'kind'           => 'page',
                    'ref'            => 'page:' . $page_id,
                    'title'          => $page['page_title'],
                    'force_draft'    => $page['force_draft'],
                    'draft_reasons'  => $page['draft_reasons'],
                    'card_auto'      => $page['card_auto'],
                    'card_review'    => $page['card_review'],
                );
            }

            foreach ($folder['children_folder_ids'] as $child_id) {
                $node['children'][] = $build_folder($child_id);
            }

            return $node;
        };

        $tree = array();
        foreach ($resolved['roots'] as $root_id) {
            $tree[] = $build_folder($root_id);
        }
        return $tree;
    }
}
