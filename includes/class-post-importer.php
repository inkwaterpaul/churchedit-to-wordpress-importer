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
     * @param array $page    One row from the `pages` table, as resolved by
     *                        CSI_Hierarchy_Resolver::build() — includes a
     *                        'tags' key (ChurchEdit tag names, from the
     *                        `tags`/`item_x_tag` tables) that gets imported
     *                        as WordPress categories.
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

            $existing = self::find_existing_post($ref);

            $restricted = !empty($page['page_access']) && $page['page_access'] !== 'everyone';
            if ($restricted) {
                $status = 'draft';
            } elseif (!$existing && !empty($options['preserve_on_update'])) {
                // A brand-new post surfaced by Compare & Update is, by
                // definition, content that's already live in ChurchEdit (it's
                // "added" relative to the last export) — publish it straight
                // away rather than defaulting to whatever the Posts step's
                // default-status dropdown happens to be set to, which is
                // meant for staging a first full import for review.
                $status = 'publish';
            } else {
                $status = $options['default_status'];
            }

            $post_data = array(
                'post_title'   => wp_strip_all_tags($page['page_title']),
                'post_content' => $content,
                'post_status'  => $status,
                'post_type'    => 'post',
            );

            $post_date = self::resolve_post_date($page);
            if ($post_date !== '') {
                $post_data['post_date'] = $post_date;
            } elseif (!$existing) {
                // No source date field survived at all — rather than let
                // wp_insert_post() silently default to right now with no
                // trace of why, flag it so a wrong date is at least visible
                // and traceable back to this page instead of looking like a
                // correctly-dated import.
                CSI_Logger::log(0, $ref, 'warning', 'No source publish date found on this page — post_date left to WordPress\'s default (now).');
            }

            // Compare & Update's targeted-update pass only wants content
            // refreshed — an existing post's publish status is left as site
            // editors currently have it. wp_update_post() merges omitted
            // keys from the existing post, so dropping it here is enough.
            if ($existing && !empty($options['preserve_on_update'])) {
                unset($post_data['post_status']);
                // Report the status the post actually keeps, not the one
                // that would have been applied had it not been preserved.
                $status = get_post($existing)->post_status;
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

            // ChurchEdit's `tags` (site-wide, e.g. "Schools", "Safeguarding")
            // are how news items get categorised on the source site — map
            // them onto WordPress's built-in category taxonomy so the same
            // grouping survives the move. Only touch categories when the
            // source actually has tags for this page; a page with none keeps
            // whatever WordPress already set (Uncategorized on creation, or
            // an editor's own categorisation on update) rather than being
            // forced back to blank.
            if (!empty($page['tags'])) {
                $category_ids = self::resolve_category_ids($page['tags']);
                if (!empty($category_ids)) {
                    wp_set_post_categories($post_id, $category_ids, false);
                }
            }

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

    /**
     * ChurchEdit carries several overlapping date columns on `pages` and none
     * of them are reliably populated on every row — try the most specific
     * first (when it was actually first published) down to the least
     * (when the row was merely added), then fall back to the unix-timestamp
     * columns if even those string dates are empty.
     *
     * @return string A 'Y-m-d H:i:s' date, or '' if nothing usable was found.
     */
    private static function resolve_post_date($page) {
        foreach (array('first_published_date', 'published_date', 'publish_date', 'date_added') as $field) {
            if (!empty($page[$field]) && $page[$field] !== '0000-00-00 00:00:00') {
                return $page[$field];
            }
        }
        // Last resort: the unix-timestamp columns are UTC-based; site-local
        // conversion isn't worth the added complexity for what's already a
        // fallback-of-a-fallback, so this is dated a few hours off on sites
        // outside UTC rather than left at today's date.
        foreach (array('publish_unix', 'unix_added') as $field) {
            if (!empty($page[$field]) && is_numeric($page[$field]) && (int) $page[$field] > 0) {
                return gmdate('Y-m-d H:i:s', (int) $page[$field]);
            }
        }
        return '';
    }

    /**
     * Map ChurchEdit tag names onto WordPress `category` term ids, creating
     * any category that doesn't already exist (matched by name, case-
     * insensitively — that's how WP itself treats term names). Reused
     * across pages so re-importing the same tag never creates a duplicate
     * category.
     *
     * @param array $tag_names
     * @return int[] Term ids; a tag that fails to resolve/create is skipped
     *               rather than aborting the whole page's import.
     */
    private static function resolve_category_ids($tag_names) {
        $ids = array();
        foreach ($tag_names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $term = get_term_by('name', $name, 'category');
            if ($term) {
                $ids[] = (int) $term->term_id;
                continue;
            }

            $created = wp_insert_term($name, 'category');
            if (is_wp_error($created)) {
                // Most likely 'term_exists' from a race with another page in
                // this same batch creating it a moment earlier — the error
                // data carries the winning term_id, so use that instead of
                // dropping the tag.
                $existing_id = $created->get_error_data('term_exists');
                if ($existing_id) {
                    $ids[] = (int) $existing_id;
                }
                continue;
            }

            $ids[] = (int) $created['term_id'];
        }
        return array_values(array_unique($ids));
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
