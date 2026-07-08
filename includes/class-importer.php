<?php
/**
 * Importer Class
 * Creates/updates WordPress pages from a resolved CSI_Hierarchy_Resolver tree.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Importer {

    /**
     * Build a parent-first ordered list of source refs to import, given the
     * resolved hierarchy and the set of folder/page ids the user selected in
     * the tree picker. Parent-first ordering guarantees post_parent lookups
     * always succeed, whether processed in one request or across many AJAX batches.
     *
     * @return array List of refs, e.g. ['stub:12', 'page:34', 'page:56', ...]
     */
    public static function get_import_order($resolved, $selected_folder_ids, $selected_page_ids) {
        $selected_folder_ids = array_flip($selected_folder_ids);
        $selected_page_ids   = array_flip($selected_page_ids);
        $order = array();

        $walk = function ($folder_id) use (&$walk, &$order, $resolved, $selected_folder_ids, $selected_page_ids) {
            $folder = $resolved['folders'][$folder_id];
            if ($folder['excluded'] || !isset($selected_folder_ids[$folder_id])) {
                return;
            }

            // The folder's own node (main_page or stub) first.
            $order[] = $folder['node_ref'];

            // Then every other page directly in the folder.
            foreach ($folder['page_ids'] as $page_id) {
                if ($resolved['pages'][$page_id]['is_folder_node']) {
                    continue;
                }
                if (isset($selected_page_ids[$page_id])) {
                    $order[] = 'page:' . $page_id;
                }
            }

            // Then recurse into subfolders.
            foreach ($folder['children_folder_ids'] as $child_id) {
                $walk($child_id);
            }
        };

        foreach ($resolved['roots'] as $root_id) {
            $walk($root_id);
        }

        return array_values(array_unique($order));
    }

    /**
     * Import a single item (folder-stub or page) by source ref.
     *
     * @param string $ref      'page:<id>' or 'stub:<folder_id>'
     * @param array  $resolved Output of CSI_Hierarchy_Resolver::build()
     * @param array  $options  ['default_status' => 'draft'|'publish', 'block_pattern' => string]
     * @return array Result: ['ref'=>, 'post_id'=>, 'title'=>, 'action'=>'created'|'updated', 'success'=>bool, 'error'=>string|null]
     */
    public static function import_item($ref, $resolved, $options = array()) {
        try {
            if (strpos($ref, 'stub:') === 0) {
                return self::import_stub(substr($ref, 5), $resolved, $options);
            }
            if (strpos($ref, 'page:') === 0) {
                return self::import_page(substr($ref, 5), $resolved, $options);
            }
            return array('ref' => $ref, 'success' => false, 'error' => 'Unknown ref format');
        } catch (Exception $e) {
            return array('ref' => $ref, 'success' => false, 'error' => 'Exception: ' . $e->getMessage());
        } catch (Error $e) {
            return array('ref' => $ref, 'success' => false, 'error' => 'Fatal error: ' . $e->getMessage());
        }
    }

    private static function import_stub($folder_id, $resolved, $options) {
        $folder = $resolved['folders'][$folder_id];
        $ref = 'stub:' . $folder_id;

        $title = $folder['folder_name'] ? $folder['folder_name'] : $folder['folder_full_name'];
        $title = ucwords(str_replace(array('-', '_'), ' ', $title));

        $parent_ref  = self::folder_parent_ref($resolved, $folder_id);
        $parent_post = self::resolve_parent_post_id($parent_ref);

        $existing = self::find_existing_post($ref);

        $post_data = array(
            'post_title'   => wp_strip_all_tags($title),
            'post_content' => self::apply_block_pattern('', $options),
            'post_status'  => $options['default_status'],
            'post_type'    => 'page',
            'post_parent'  => $parent_post,
            'menu_order'   => isset($folder['folder_order']) ? (int) $folder['folder_order'] : 0,
        );

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
        update_post_meta($post_id, '_ce_folder_id', $folder_id);

        CSI_Logger::log($post_id, $ref, 'success', $action);

        return array('ref' => $ref, 'post_id' => $post_id, 'title' => $title, 'action' => $action, 'success' => true);
    }

    private static function import_page($page_id, $resolved, $options) {
        $page = $resolved['pages'][$page_id];
        $ref  = 'page:' . $page_id;

        $parent_post = self::resolve_parent_post_id($page['parent_ref']);

        // Pull out "flexContainer" page-link card grids before normal block
        // conversion — they need a second pass once every page has a WP post
        // ID (see CSI_Page_Links_Resolver), so a numbered placeholder comment
        // is appended in their place for now.
        $url_index = isset($options['url_index']) ? $options['url_index'] : array('pages' => array(), 'folders' => array());
        $extraction = CSI_Page_Links_Extractor::extract($page['page_content'], $url_index);

        // Placeholders must be appended before apply_block_pattern() runs, so
        // they land inside the {content} substitution (e.g. inside the body
        // column of a sidebar-navigation layout) rather than after the whole
        // wrapped structure.
        $body = CSI_Content_Converter::convert($extraction['html']);
        foreach ($extraction['groups'] as $i => $group) {
            $body .= "\n\n<!-- ce:page-links:{$i} -->\n\n";
        }
        $content = self::apply_block_pattern($body, $options);

        $status = $page['force_draft'] ? 'draft' : $options['default_status'];

        $existing = self::find_existing_post($ref);

        $post_data = array(
            'post_title'   => wp_strip_all_tags($page['page_title']),
            'post_content' => $content,
            'post_excerpt' => self::generate_excerpt($extraction['html']),
            'post_status'  => $status,
            'post_type'    => 'page',
            'post_parent'  => $parent_post,
            'menu_order'   => self::resolve_page_menu_order($page, $resolved),
        );

        if (!empty($page['first_published_date'])) {
            $post_data['post_date'] = $page['first_published_date'];
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

        if (!empty($page['draft_reasons'])) {
            update_post_meta($post_id, '_ce_draft_reasons', $page['draft_reasons']);
        }

        if ($page['card_auto'] || $page['card_review']) {
            update_post_meta($post_id, '_ce_card_query', $page['card_query']);
            update_post_meta($post_id, '_ce_card_status', $page['card_auto'] ? 'auto' : 'review');
        }

        if (!empty($extraction['groups'])) {
            update_post_meta($post_id, '_ce_pending_page_links', $extraction['groups']);
        } else {
            delete_post_meta($post_id, '_ce_pending_page_links');
        }

        // Capture pending image/doc references for the later Link Media pass.
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
    }

    /**
     * A page's `page_order` only ranks it among *sibling pages inside the
     * same ChurchEdit folder* — meaningless for a page that's a folder's own
     * node (its main_page), since that page's real WP siblings are other
     * folder nodes, not the other pages in its own folder. ChurchEdit leaves
     * page_order at the default '1' for the vast majority of such pages
     * regardless of their real position, so using it there would collapse
     * every sibling folder's node to the same menu_order. The folder's own
     * folder_order is the correct source for those (confirmed against real
     * data — folder_order gives a clean 1..N sequence per folder level,
     * while main_page page_order is '1' for almost all of them).
     */
    private static function resolve_page_menu_order($page, $resolved) {
        if (!empty($page['is_folder_node']) && isset($resolved['folders'][$page['folder_id']])) {
            $folder_order = $resolved['folders'][$page['folder_id']]['folder_order'];
            return isset($folder_order) ? (int) $folder_order : 0;
        }
        return isset($page['page_order']) ? (int) $page['page_order'] : 0;
    }

    /**
     * Wrap converted content in the given block layout pattern, replacing the
     * {content} placeholder. Returns $content unchanged if no pattern is set.
     */
    public static function apply_block_pattern($content, $options) {
        if (empty($options['block_pattern'])) {
            return $content;
        }
        return str_replace('{content}', trim($content), $options['block_pattern']);
    }

    /**
     * Plain-text excerpt derived from the raw source HTML, capped at 20 words.
     * Runs on the raw page_content (before block conversion) rather than the
     * converted output, since the converted version contains
     * <!-- wp:paragraph --> style block comments that wp_strip_all_tags()
     * wouldn't remove (they're comments, not tags) and would otherwise leak
     * into the excerpt as literal text.
     */
    private static function generate_excerpt($html, $max_words = 20) {
        // strip_tags()/wp_strip_all_tags() remove the tags but not their
        // content — a handful of source pages (mostly ChurchEdit's own
        // "Templates" demo pages) embed <style>/<script> blocks whose raw
        // CSS/JS would otherwise leak into the excerpt as text.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $html);

        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if ($text === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $text);
        if (count($words) <= $max_words) {
            return $text;
        }

        return implode(' ', array_slice($words, 0, $max_words)) . '…';
    }

    /**
     * A folder's node page attaches to the parent folder's node — walk up past
     * any excluded ancestors to find it (mirrors the resolver's own logic).
     */
    private static function folder_parent_ref($resolved, $folder_id) {
        $folder = $resolved['folders'][$folder_id];
        $parent_id = $folder['parent_id'];
        if ($parent_id === null || $parent_id === '' || $parent_id === '0') {
            return 'root';
        }
        $parent_id = (string) $parent_id;
        if (!isset($resolved['folders'][$parent_id]) || $resolved['folders'][$parent_id]['excluded']) {
            return 'root';
        }
        return $resolved['folders'][$parent_id]['node_ref'];
    }

    /**
     * Resolve a source ref ('root', 'page:<id>', 'stub:<id>') to a WP post ID,
     * via postmeta lookup so it works statelessly across separate AJAX batches.
     */
    private static function resolve_parent_post_id($parent_ref) {
        if ($parent_ref === 'root' || empty($parent_ref)) {
            return 0;
        }
        $existing = self::find_existing_post($parent_ref);
        return $existing ? $existing : 0;
    }

    private static function find_existing_post($ref) {
        $existing = get_posts(array(
            'post_type'      => 'page',
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
