<?php
/**
 * Page Links Resolver Class
 *
 * Second pass, run after all content types have been imported: for every
 * post still carrying a `_ce_pending_page_links` marker (set by
 * CSI_Page_Links_Extractor during the initial page import — see
 * CSI_Importer::import_page()), resolves each card's old ChurchEdit page_id
 * to whatever new WP post it became, and replaces the numbered placeholder
 * comment left in the content with a real `octopus/page-links` block.
 *
 * This needs its own pass rather than resolving inline during import because
 * a card can link to a page that hasn't been created yet at that point (or
 * ever, since the tree/folder is walked in a fixed order and cards can point
 * anywhere in the site).
 *
 * One-shot by design: every post visited gets its pending meta cleared
 * unconditionally (unlike Link Media, there's nothing to "wait and retry" —
 * once every content type is imported, a card that still doesn't resolve
 * genuinely wasn't imported and won't become resolvable later). It still
 * tracks visited IDs per run as defense-in-depth against ever repeating a post.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Page_Links_Resolver {

    /**
     * @param int[] $exclude_ids Post IDs already processed earlier in this run
     */
    public static function resolve_batch($batch_size = 10, $exclude_ids = array()) {
        $results = array(
            'links_resolved' => 0,
            'posts_updated'  => 0,
            'batch_count'    => 0,
            'post_ids'       => array(),
        );

        $query_args = array(
            'post_type'      => array('page', 'post', 'vacancy', 'tribe_events'),
            'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => $batch_size,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_ce_pending_page_links', 'compare' => 'EXISTS'),
            ),
        );
        if (!empty($exclude_ids)) {
            $query_args['post__not_in'] = $exclude_ids;
        }

        $posts = get_posts($query_args);
        $results['batch_count'] = count($posts);
        $results['post_ids']    = $posts;

        foreach ($posts as $post_id) {
            self::resolve_post($post_id, $results);
        }

        return $results;
    }

    private static function resolve_post($post_id, &$results) {
        $groups = get_post_meta($post_id, '_ce_pending_page_links', true);

        if (empty($groups) || !is_array($groups)) {
            delete_post_meta($post_id, '_ce_pending_page_links');
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            delete_post_meta($post_id, '_ce_pending_page_links');
            return;
        }

        $content = $post->post_content;

        foreach ($groups as $i => $group) {
            $placeholder = '<!-- ce:page-links:' . $i . ' -->';
            if (strpos($content, $placeholder) === false) {
                continue;
            }
            $content = str_replace($placeholder, self::build_block($group), $content);
            $results['links_resolved'] += count($group['page_refs']) + count($group['external_links']);
        }

        wp_update_post(array('ID' => $post_id, 'post_content' => $content));
        delete_post_meta($post_id, '_ce_pending_page_links');
        $results['posts_updated']++;
    }

    /**
     * Build a self-closing octopus/page-links block from one extracted
     * group. postIDs only ever resolves post_type=page (the block's own
     * render_callback hardcodes that query), so a page_ref that resolved to
     * a Post/Vacancy/Event is rendered as an external-style link to its real
     * permalink instead; a page_ref that never got imported at all falls
     * back to the original ChurchEdit title/url/excerpt captured at
     * extraction time, so nothing is silently lost either way.
     */
    private static function build_block($group) {
        $post_ids       = array();
        $selected_posts = array();
        $external_links = array();

        foreach ($group['page_refs'] as $ref) {
            $target = self::find_new_post($ref['old_page_id']);

            if ($target && $target['post_type'] === 'page') {
                $post_ids[]       = $target['ID'];
                $selected_posts[] = array('id' => $target['ID'], 'title' => $target['post_title']);
            } elseif ($target) {
                $external_links[] = array(
                    'url'     => get_permalink($target['ID']),
                    'title'   => $target['post_title'],
                    'excerpt' => $ref['fallback_excerpt'],
                );
            } else {
                $external_links[] = array(
                    'url'     => $ref['fallback_url'],
                    'title'   => $ref['fallback_title'],
                    'excerpt' => $ref['fallback_excerpt'],
                );
            }
        }

        foreach ($group['external_links'] as $link) {
            $external_links[] = $link;
        }

        $attrs = array(
            // Switches the block's registered "compact" style (its block.json
            // labels this "List") on instead of the default grid — this is a
            // real WP block style variation, not a raw CSS class: the block's
            // own render_callback reads className directly (see
            // octopus-blocks' page-links render.php) rather than relying on
            // saved wrapper markup, so setting it here is enough.
            'className' => 'is-style-compact',
        );
        if (!empty($post_ids)) {
            $attrs['postIDs']       = array_values(array_unique($post_ids));
            $attrs['selectedPosts'] = $selected_posts;
        }
        if (!empty($external_links)) {
            $attrs['externalLinks'] = array_values($external_links);
        }

        if (empty($post_ids) && empty($external_links)) {
            return '';
        }

        return '<!-- wp:octopus/page-links ' . wp_json_encode($attrs) . ' /-->';
    }

    private static function find_new_post($old_page_id) {
        $existing = get_posts(array(
            'post_type'      => array('page', 'post', 'vacancy'),
            'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_ce_page_id', 'value' => $old_page_id),
            ),
        ));
        if (empty($existing)) {
            return null;
        }
        $post_id = (int) $existing[0];
        return array(
            'ID'         => $post_id,
            'post_type'  => get_post_type($post_id),
            'post_title' => get_the_title($post_id),
        );
    }
}
