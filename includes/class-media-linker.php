<?php
/**
 * Media Linker Class
 *
 * Images and documents are uploaded to the media library independently of
 * this plugin (plain WordPress uploads). This class's only job is: for posts
 * with pending media references, look up each filename directly in the media
 * library and rewrite the post's content to point at the matching attachment
 * if one exists. Batched — this touches post content in bulk, and iterating
 * every pending post's meta and rewriting content risks a PHP/webserver
 * timeout if done all at once for any real page count.
 *
 * Run separately per post type (page, post, vacancy, tribe_events — see
 * CSI_Content_Converter::extract_pending_media() callers for the full set an
 * importer can attach pending media to) rather than across all of them in one
 * query, so each run stays small and predictable instead of one query/batch
 * loop spanning every content type on the site at once.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Media_Linker {

    /**
     * Resolve pending media on the next $batch_size posts of the given post
     * type that still have any, by looking each filename up directly in the
     * media library.
     *
     * Posts that resolve drop their pending meta key and fall out of the
     * query naturally — but a post whose file genuinely isn't in the media
     * library yet never resolves, so it would otherwise be returned by every
     * subsequent call forever (the loop never terminates). $exclude_ids tracks
     * every post ID already visited this run so each post is only ever
     * touched once per click, guaranteeing the batch loop actually ends.
     *
     * @param int[] $exclude_ids Post IDs already processed earlier in this run
     */
    public static function link_batch($post_type, $batch_size = 10, $exclude_ids = array()) {
        $results = array(
            'images_updated' => 0,
            'docs_updated'   => 0,
            'pages_updated'  => 0,
            'still_missing'  => array(),
            'batch_count'    => 0,
            'post_ids'       => array(),
            'total_pending'  => 0,
        );

        $meta_query = array(
            'relation' => 'OR',
            array('key' => '_ce_pending_images', 'compare' => 'EXISTS'),
            array('key' => '_ce_pending_docs', 'compare' => 'EXISTS'),
        );

        $count_query = new WP_Query(array(
            'post_type'      => $post_type,
            'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        ));
        $results['total_pending'] = (int) $count_query->found_posts;

        $query_args = array(
            'post_type'      => $post_type,
            'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => $batch_size,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        );
        if (!empty($exclude_ids)) {
            $query_args['post__not_in'] = $exclude_ids;
        }

        $pages = get_posts($query_args);

        $results['batch_count'] = count($pages);
        $results['post_ids']    = $pages;

        if (empty($pages)) {
            return $results;
        }

        $attach_cache = array();

        foreach ($pages as $page_id) {
            self::resolve_page($page_id, $attach_cache, $results);
        }

        $results['still_missing'] = self::dedupe_still_missing($results['still_missing']);

        return $results;
    }

    private static function resolve_page($page_id, &$attach_cache, &$results) {
        $page = get_post($page_id);
        if (!$page) {
            return;
        }
        $content = $page->post_content;
        $changed = false;

        $pending_images = get_post_meta($page_id, '_ce_pending_images', true);
        if (!empty($pending_images) && is_array($pending_images)) {
            $still_pending      = array();
            $first_image_id     = null;
            $first_image_new_url = null;
            $already_has_thumb  = has_post_thumbnail($page_id);

            foreach ($pending_images as $item) {
                $filename = $item['filename'];
                $orig_src = $item['original_src'];

                if (!array_key_exists($filename, $attach_cache)) {
                    $attach_cache[$filename] = self::find_attachment_by_filename($filename);
                }
                $attach_id = $attach_cache[$filename];

                if ($attach_id) {
                    $new_url = wp_get_attachment_url($attach_id);
                    $content = str_replace('src="' . $orig_src . '"', 'src="' . $new_url . '"', $content);
                    $changed = true;
                    $results['images_updated']++;
                    if ($first_image_id === null && !$already_has_thumb) {
                        $first_image_id      = $attach_id;
                        $first_image_new_url = $new_url;
                    }
                } else {
                    $still_pending[] = $item;
                    $results['still_missing'][] = array(
                        'filename'   => $filename,
                        'post_id'    => $page_id,
                        'post_title' => get_the_title($page_id),
                    );
                }
            }

            if (empty($still_pending)) {
                delete_post_meta($page_id, '_ce_pending_images');
            } else {
                update_post_meta($page_id, '_ce_pending_images', $still_pending);
            }

            if ($first_image_id) {
                set_post_thumbnail($page_id, $first_image_id);
                // It's now the featured image, so drop its own image block from
                // the body rather than showing it twice.
                $content = self::strip_image_block($content, $first_image_new_url);
            }
        }

        $pending_docs = get_post_meta($page_id, '_ce_pending_docs', true);
        if (!empty($pending_docs) && is_array($pending_docs)) {
            $still_pending = array();

            foreach ($pending_docs as $item) {
                $filename  = $item['filename'];
                $orig_href = $item['original_href'];

                if (!array_key_exists($filename, $attach_cache)) {
                    $attach_cache[$filename] = self::find_attachment_by_filename($filename);
                }
                $attach_id = $attach_cache[$filename];

                if ($attach_id) {
                    $new_url = wp_get_attachment_url($attach_id);
                    $content = str_replace('href="' . $orig_href . '"', 'href="' . $new_url . '"', $content);
                    $changed = true;
                    $results['docs_updated']++;
                } else {
                    $still_pending[] = $item;
                    $results['still_missing'][] = array(
                        'filename'   => $filename,
                        'post_id'    => $page_id,
                        'post_title' => get_the_title($page_id),
                    );
                }
            }

            if (empty($still_pending)) {
                delete_post_meta($page_id, '_ce_pending_docs');
            } else {
                update_post_meta($page_id, '_ce_pending_docs', $still_pending);
            }
        }

        if ($changed) {
            wp_update_post(array('ID' => $page_id, 'post_content' => $content));
            $results['pages_updated']++;
        }
    }

    /**
     * De-dupe still-missing entries by filename+post, so a file referenced
     * more than once in the same post's content is only reported once.
     * (array_unique() doesn't work here since each entry is an array.)
     */
    private static function dedupe_still_missing($entries) {
        $seen    = array();
        $deduped = array();

        foreach ($entries as $entry) {
            $key = $entry['filename'] . '|' . $entry['post_id'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[]  = $entry;
            }
        }

        return $deduped;
    }

    /**
     * Remove the <!-- wp:image -->...<!-- /wp:image --> block containing the
     * given (already-rewritten) attachment URL — used once that image has
     * been set as the featured image, so it isn't also shown in the body.
     * CSI_Content_Converter always emits bare `<!-- wp:image -->` (no JSON
     * attrs) for every image-producing case, so this pattern is exhaustive.
     */
    private static function strip_image_block($content, $url) {
        $pattern = '/<!-- wp:image -->.*?src="' . preg_quote($url, '/') . '".*?<!-- \/wp:image -->\n*/s';
        return preg_replace($pattern, '', $content, 1);
    }

    /**
     * Look up an attachment by its original filename. Since these files are
     * uploaded independently (not by this plugin), there's no custom meta
     * marker to key off — this matches WordPress's own stored filename
     * (`_wp_attached_file`, e.g. "2026/07/photo.jpg") by its basename, trying
     * both the raw ChurchEdit filename and its sanitize_file_name() form
     * (WordPress sanitizes filenames on upload, e.g. spaces become hyphens).
     *
     * The LIKE is deliberately broad (just "ends with the candidate"), so
     * candidates are re-checked for an exact basename match in PHP before
     * being accepted — otherwise e.g. candidate "2.jpg" would also match a
     * stored path like "2024/05/img2.jpg" (which also ends in "2.jpg"),
     * silently linking the wrong image. This was the cause of images
     * occasionally getting mixed up on import.
     */
    private static function find_attachment_by_filename($filename) {
        global $wpdb;

        foreach (array_unique(array($filename, sanitize_file_name($filename))) as $candidate) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s
                 ORDER BY post_id DESC",
                '%' . $wpdb->esc_like($candidate)
            ));

            foreach ($rows as $row) {
                if (wp_basename($row->meta_value) === $candidate) {
                    return (int) $row->post_id;
                }
            }
        }

        return false;
    }
}
