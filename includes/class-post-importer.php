<?php
/**
 * Post Importer Class
 *
 * Flat (non-hierarchical) import of a single ChurchEdit folder's pages as
 * WordPress Posts — for folders like "news" that are blog archives, not a page
 * tree. Reuses the same content conversion and pending-media capture as the
 * page importer, just without any post_parent/hierarchy concerns.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Post_Importer {

    /**
     * @param array $page    One row from the `pages` table
     * @param array $options ['default_status' => 'draft'|'publish'|'pending']
     * @return array Result, same shape as CSI_Importer::import_item()
     */
    public static function import_post($page, $options = array()) {
        try {
            $page_id = $page['page_id'];
            $ref = 'post:' . $page_id;

            // The block layout pattern (sidebar page-navigation wrapper) is a
            // Pages-only concept — Posts don't use it.
            $content = CSI_Content_Converter::convert($page['page_content']);
            $status  = (!empty($page['page_access']) && $page['page_access'] !== 'everyone') ? 'draft' : $options['default_status'];

            $existing = self::find_existing_post($ref);

            $post_data = array(
                'post_title'   => wp_strip_all_tags($page['page_title']),
                'post_content' => $content,
                'post_status'  => $status,
                'post_type'    => 'post',
            );

            if (!empty($page['first_published_date'])) {
                $post_data['post_date'] = $page['first_published_date'];
            } elseif (!empty($page['published_date'])) {
                $post_data['post_date'] = $page['published_date'];
            }

            if ($existing) {
                $post_data['ID'] = $existing;
                $post_id = wp_update_post($post_data, true);
                $action = 'updated';
            } else {
                $post_data['post_author'] = get_current_user_id();
                $post_id = wp_insert_post($post_data, true);
                $action = 'created';
            }

            if (is_wp_error($post_id)) {
                return array('ref' => $ref, 'success' => false, 'error' => $post_id->get_error_message());
            }

            update_post_meta($post_id, '_ce_source_ref', $ref);
            update_post_meta($post_id, '_ce_page_id', $page_id);
            update_post_meta($post_id, '_ce_folder_id', $page['folder_id']);

            $pending = CSI_Content_Converter::extract_pending_media($content);
            if (!empty($pending['images'])) {
                update_post_meta($post_id, '_ce_pending_images', $pending['images']);
            } else {
                delete_post_meta($post_id, '_ce_pending_images');
            }
            if (!empty($pending['docs'])) {
                update_post_meta($post_id, '_ce_pending_docs', $pending['docs']);
            } else {
                delete_post_meta($post_id, '_ce_pending_docs');
            }

            CSI_Logger::log($post_id, $ref, 'success', $action);

            return array(
                'ref'     => $ref,
                'post_id' => $post_id,
                'title'   => $page['page_title'],
                'action'  => $action,
                'success' => true,
                'draft'   => ($status === 'draft'),
            );
        } catch (Exception $e) {
            return array('ref' => 'post:' . $page['page_id'], 'success' => false, 'error' => 'Exception: ' . $e->getMessage());
        } catch (Error $e) {
            return array('ref' => 'post:' . $page['page_id'], 'success' => false, 'error' => 'Fatal error: ' . $e->getMessage());
        }
    }

    private static function find_existing_post($ref) {
        $existing = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_ce_source_ref', 'value' => $ref),
            ),
        ));
        return !empty($existing) ? (int) $existing[0] : 0;
    }
}
