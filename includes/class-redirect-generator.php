<?php
/**
 * Redirect Generator Class
 *
 * Builds a list of regex redirects from old ChurchEdit folder URLs to their
 * new WordPress page paths, one per line in "pattern target" form, e.g.
 *
 *   ^/whoweare/seniorclergy(/.*)?$ /who-we-are/senior-clergy$1
 *   ^/whoweare(?!/(?:seniorclergy)(?:/|$))(/.*)?$ /who-we-are$1
 *
 * ChurchEdit URLs are built from folder_full_name (e.g. "whoweare"), while
 * imported pages get their slug from their title (e.g. "who-we-are"), so any
 * URL under a folder whose name doesn't match its new slug would 404. Rules
 * are folder-level with a trailing (/.*)? capture, so everything beneath a
 * folder follows it. A rule is only written where a folder's new path differs
 * from what its nearest rewritten ancestor would already produce; each rule
 * then excludes its own rewritten descendants with a negative lookahead, so
 * the lines work regardless of the order they're evaluated in.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Redirect_Generator {

    /** @var array source ref => WP_Post, for pages already imported */
    private static $posts_by_ref = array();

    /** @var array source ref => new path (memo) */
    private static $paths = array();

    /** @var array new parent path => [slug => true], for predicted slugs */
    private static $used_slugs = array();

    /**
     * @param array $resolved Output of CSI_Hierarchy_Resolver::build()
     * @return array{text: string, count: int}
     */
    public static function build($resolved) {
        self::$posts_by_ref = self::load_imported_pages();
        self::$paths = array();
        self::$used_slugs = array();

        $rules = array();
        foreach ($resolved['roots'] as $root_id) {
            $no_rule = null;
            self::collect($resolved, $root_id, $no_rule, null, $rules);
        }

        $lines = array();
        foreach ($rules as $rule) {
            self::render($rule, $lines);
        }

        return array(
            'text'  => implode("\n", $lines) . ($lines ? "\n" : ''),
            'count' => count($lines),
        );
    }

    /**
     * Walk the folder tree, attaching a rule wherever a folder's new path
     * isn't what its governing (nearest rewritten ancestor) rule implies.
     *
     * @param array|null $governing Reference to the nearest ancestor rule
     * @param string|null $parent_old Old path of the parent folder
     */
    private static function collect($resolved, $folder_id, &$governing, $parent_old, &$top_level) {
        $folder = $resolved['folders'][$folder_id];
        if ($folder['excluded']) {
            return;
        }

        $old = '/' . implode('/', CSI_Hierarchy_Resolver::folder_url_segments($resolved, $folder_id, true));
        $new = self::node_path($resolved, $folder['node_ref']);

        // A -noshow folder is left out of ChurchEdit's live URLs, so it shares
        // its parent's old path — there's nothing of its own to redirect, but
        // its subfolders still need checking against the same governing rule.
        $own_rule = null;
        if ($old !== $parent_old && $new !== '') {
            $implied = $governing ? $governing['new'] . substr($old, strlen($governing['old'])) : $old;
            if ($new !== $implied) {
                $own_rule = array('old' => $old, 'new' => $new, 'children' => array());
            }
        }

        if ($own_rule) {
            foreach ($folder['children_folder_ids'] as $child_id) {
                self::collect($resolved, $child_id, $own_rule, $old, $top_level);
            }
            if ($governing) {
                $governing['children'][] = $own_rule;
            } else {
                $top_level[] = $own_rule;
            }
        } else {
            foreach ($folder['children_folder_ids'] as $child_id) {
                self::collect($resolved, $child_id, $governing, $old, $top_level);
            }
        }
    }

    /**
     * Append a rule's lines: its rewritten descendants first, then the rule
     * itself with those descendants excluded from its own match.
     */
    private static function render($rule, &$lines) {
        $excluded = array();
        foreach ($rule['children'] as $child) {
            self::render($child, $lines);
            $excluded[] = self::path_regex(ltrim(substr($child['old'], strlen($rule['old'])), '/'));
        }

        $lookahead = $excluded ? '(?!/(?:' . implode('|', $excluded) . ')(?:/|$))' : '';
        $lines[] = '^' . self::path_regex($rule['old']) . $lookahead . '(/.*)?$ ' . $rule['new'] . '$1';
    }

    /**
     * Regex-escape a URL path, matching spaces either raw or %20-encoded
     * (folder_full_name can contain spaces, e.g. "Engagement and Fundraising").
     * Hyphens and slashes are left as-is, they're literal outside a class.
     */
    private static function path_regex($path) {
        $escaped = preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $path);
        return str_replace(' ', '(?:%20|\s)', $escaped);
    }

    /**
     * New WordPress path (no trailing slash) for a folder/page node ref. Uses
     * the real imported page where there is one — catching any slug WordPress
     * de-duplicated or an editor has since changed — and otherwise predicts
     * it the same way an import would: parent's path plus the title's slug.
     */
    private static function node_path($resolved, $ref) {
        if ($ref === null || $ref === '' || $ref === 'root') {
            return '';
        }
        if (isset(self::$paths[$ref])) {
            return self::$paths[$ref];
        }

        if (isset(self::$posts_by_ref[$ref])) {
            $path = self::post_path(self::$posts_by_ref[$ref]);
        } else {
            if (strpos($ref, 'stub:') === 0) {
                $folder = $resolved['folders'][substr($ref, 5)];
                $title = $folder['folder_name'] ? $folder['folder_name'] : $folder['folder_full_name'];
                $title = ucwords(str_replace(array('-', '_'), ' ', $title));
                $parent_ref = self::folder_parent_ref($resolved, substr($ref, 5));
            } else {
                $page = $resolved['pages'][substr($ref, 5)];
                $title = $page['page_title'];
                $parent_ref = $page['parent_ref'];
            }
            $parent_path = self::node_path($resolved, $parent_ref);
            $path = $parent_path . '/' . self::unique_slug($parent_path, sanitize_title(wp_strip_all_tags($title)));
        }

        self::$paths[$ref] = $path;
        return $path;
    }

    /**
     * Mirrors CSI_Importer::folder_parent_ref().
     */
    private static function folder_parent_ref($resolved, $folder_id) {
        $parent_id = (string) $resolved['folders'][$folder_id]['parent_id'];
        if ($parent_id === '' || $parent_id === '0' || !isset($resolved['folders'][$parent_id]) || $resolved['folders'][$parent_id]['excluded']) {
            return 'root';
        }
        return $resolved['folders'][$parent_id]['node_ref'];
    }

    /**
     * Sibling pages can't share a slug — WordPress appends -2, -3, ... in
     * import order, which the parent-first tree walk here follows too.
     */
    private static function unique_slug($parent_path, $slug) {
        $candidate = $slug;
        $n = 2;
        while (isset(self::$used_slugs[$parent_path][$candidate])) {
            $candidate = $slug . '-' . $n++;
        }
        self::$used_slugs[$parent_path][$candidate] = true;
        return $candidate;
    }

    /**
     * Path of an existing page from its post_name chain. Drafts have no
     * post_name until published, so fall back to the slug their title will
     * get at that point.
     */
    private static function post_path($post) {
        $segments = array();
        $seen = array();
        while ($post && !isset($seen[$post->ID])) {
            $seen[$post->ID] = true;
            $segments[] = $post->post_name !== '' ? $post->post_name : sanitize_title($post->post_title);
            $post = $post->post_parent ? get_post($post->post_parent) : null;
        }
        $path = '/' . implode('/', array_reverse($segments));
        $parent = dirname($path);
        self::$used_slugs[$parent === '/' ? '' : $parent][basename($path)] = true;
        return $path;
    }

    private static function load_imported_pages() {
        $posts = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => -1,
            'meta_key'       => '_ce_source_ref',
        ));
        $by_ref = array();
        foreach ($posts as $post) {
            $ref = get_post_meta($post->ID, '_ce_source_ref', true);
            if ($ref && !isset($by_ref[$ref])) {
                $by_ref[$ref] = $post;
            }
        }
        return $by_ref;
    }
}
