<?php
/**
 * Hierarchy Resolver Class
 *
 * ChurchEdit stores hierarchy on a separate `folders` table (parent_id tree + a
 * main_page pointer), not on `pages` itself. This class turns that into a tree of
 * WP-page-shaped nodes: each folder becomes either its main_page (if set) or a
 * synthetic stub page, other pages in the folder become its children, and
 * subfolders nest recursively underneath.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Hierarchy_Resolver {

    const LARGE_FOLDER_THRESHOLD = 100;

    /**
     * @param array $folders   Rows from the `folders` table (as assoc arrays)
     * @param array $pages     Rows from the `pages` table (as assoc arrays)
     * @param array $page_tags Optional: page_id => [tag name, ...], built from
     *                         the `tags`/`item_x_tag` tables (see
     *                         CSI_AJAX_Handler::build_page_tag_names()).
     * @return array{folders: array, pages: array, roots: array}
     */
    public static function build($folders, $pages, $page_tags = array()) {
        $folders_by_id = array();
        foreach ($folders as $f) {
            $id = (string) $f['folder_id'];
            $f['children_folder_ids'] = array();
            $f['page_ids'] = array();
            $f['excluded'] = false;
            $f['exclude_reason'] = null;
            $f['large_folder'] = false;
            $f['node_ref'] = null; // 'page:<id>' or 'stub:<folder_id>', set below
            $folders_by_id[$id] = $f;
        }

        // Wire up parent -> children
        foreach ($folders_by_id as $id => $f) {
            $parent_id = self::norm_id($f['parent_id']);
            if ($parent_id !== null && isset($folders_by_id[$parent_id])) {
                $folders_by_id[$parent_id]['children_folder_ids'][] = $id;
            }
        }

        // Mark feature/xdb folders excluded (CMS modules, not generic content)
        foreach ($folders_by_id as $id => $f) {
            if (in_array($f['type'], array('feature', 'xdb'), true)) {
                $folders_by_id[$id]['excluded'] = true;
                $folders_by_id[$id]['exclude_reason'] = sprintf(
                    __('CMS feature module (%s) — not generic content, needs bespoke handling.', 'churchedit-sql-importer'),
                    $f['folder_destination'] ? $f['folder_destination'] : $f['folder_name']
                );
            }
        }

        $pages_by_id = array();
        foreach ($pages as $p) {
            $id = (string) $p['page_id'];
            $p['force_draft'] = false;
            $p['draft_reasons'] = array();
            $p['is_folder_node'] = false;
            $p['card_auto'] = false;
            $p['card_review'] = false;
            $p['card_query'] = null;
            $p['parent_ref'] = null; // filled in below
            $p['tags'] = isset($page_tags[$id]) ? $page_tags[$id] : array();

            // Private/restricted in the source system -> draft regardless of folder
            if (!empty($p['page_access']) && $p['page_access'] !== 'everyone') {
                $p['force_draft'] = true;
                $p['draft_reasons'][] = sprintf(
                    __('Restricted access in source (%s)', 'churchedit-sql-importer'),
                    $p['page_access']
                );
            }

            // Card grid config
            if (!empty($p['page_options'])) {
                $opts = json_decode($p['page_options'], true);
                if (is_array($opts)) {
                    $enabled = isset($opts['cardsEnabled']) ? (string) $opts['cardsEnabled'] : '';
                    $source  = isset($opts['cardsGeneratedFrom']) ? $opts['cardsGeneratedFrom'] : '';
                    if ($enabled === '1') {
                        $p['card_auto'] = true;
                        $p['card_query'] = $opts;
                    } elseif ($source === 'folder' && $enabled !== '0') {
                        $p['card_review'] = true;
                        $p['card_query'] = $opts;
                    }
                }
            }

            $pages_by_id[$id] = $p;

            $folder_id = self::norm_id($p['folder_id']);
            if ($folder_id !== null && isset($folders_by_id[$folder_id])) {
                $folders_by_id[$folder_id]['page_ids'][] = $id;
            }
        }

        // -noshow folders (and anything nested under them) -> force draft on their pages
        foreach ($folders_by_id as $id => $f) {
            if (strpos($f['folder_full_name'], '-noshow') !== false) {
                self::mark_subtree_draft($folders_by_id, $pages_by_id, $id, __('Hidden/archived folder in source (-noshow)', 'churchedit-sql-importer'));
            }
        }

        // Large flat folders (e.g. "news", 306 pages) are almost certainly blog
        // archives, not a page hierarchy. Exclude them from the Pages tree
        // entirely (not just unchecked-by-default) — leaving them selectable
        // there was confusing and led to importing hundreds of news items as
        // Pages instead of Posts. They're picked up by the dedicated "Import
        // Folder as Posts" step instead, which reads large_folder below.
        foreach ($folders_by_id as $id => $f) {
            if (!$f['excluded'] && count($f['page_ids']) > self::LARGE_FOLDER_THRESHOLD) {
                $folders_by_id[$id]['large_folder'] = true;
                $folders_by_id[$id]['excluded'] = true;
                $folders_by_id[$id]['exclude_reason'] = sprintf(
                    __('Large folder (%d pages) — likely a blog/news archive. Use "Import Folder as Posts" below instead of the Pages tree.', 'churchedit-sql-importer'),
                    count($f['page_ids'])
                );
            }
        }

        // Vacancy listings live as regular pages under a folder whose name
        // signals it's a job/vacancy section (in this export: "vacancieslist",
        // found via the `redirect` table mapping old /job-vacancies/ URLs to
        // this folder), with each direct child folder acting as a category
        // (e.g. "Diocesan Roles", "Full Time Stipendiary"). Exclude the whole
        // subtree from the Pages tree — it's handled by the dedicated
        // "Import Vacancies" step instead, which maps child folders to
        // 'vacancy-category' terms on the 'vacancy' post type.
        $vacancy_root_folder_ids = array();
        foreach ($folders_by_id as $id => $f) {
            if (!$f['excluded'] && stripos($f['folder_full_name'], 'vacan') !== false) {
                $vacancy_root_folder_ids[] = $id;
                self::mark_subtree_excluded($folders_by_id, $id, __('Vacancy listing — import via the dedicated Vacancies importer instead.', 'churchedit-sql-importer'));
            }
        }

        // Assign each non-excluded folder a node_ref, in priority order:
        //   1. its main_page, if set and present among imported pages;
        //   2. a page inside it whose own filename matches the folder's name
        //      — ChurchEdit's implicit "index page" convention for folders
        //      that never got an explicit main_page (confirmed against real
        //      data, e.g. folder "senior-clergy-staff" has no main_page but
        //      contains senior-clergy-staff.php — without this, that page
        //      imports twice: once as an empty stub, once as its own
        //      identically-titled child);
        //   3. for a "-noshow" folder specifically — which by definition
        //      isn't meant to be a distinct visible level at all — become
        //      transparent, borrowing its parent's node so its children
        //      attach directly there instead of under an empty stub titled
        //      "X (NoShow)" (that literal text is the folder's real
        //      ChurchEdit display name in these cases);
        //   4. otherwise, a synthetic stub.
        foreach ($folders_by_id as $id => $f) {
            $folders_by_id[$id]['is_transparent'] = false;
        }

        $resolve_node_ref = function ($id) use (&$resolve_node_ref, &$folders_by_id, &$pages_by_id) {
            if ($folders_by_id[$id]['node_ref'] !== null) {
                return $folders_by_id[$id]['node_ref'];
            }

            $main_page_id = self::norm_id($folders_by_id[$id]['main_page']);
            if ($main_page_id !== null && isset($pages_by_id[$main_page_id])) {
                $folders_by_id[$id]['node_ref'] = 'page:' . $main_page_id;
                $pages_by_id[$main_page_id]['is_folder_node'] = true;
                return $folders_by_id[$id]['node_ref'];
            }

            $implicit_id = self::find_index_named_page($folders_by_id, $pages_by_id, $id, $folders_by_id[$id]['folder_full_name']);
            if ($implicit_id !== null) {
                $folders_by_id[$id]['node_ref'] = 'page:' . $implicit_id;
                $pages_by_id[$implicit_id]['is_folder_node'] = true;
                return $folders_by_id[$id]['node_ref'];
            }

            $parent_id = self::norm_id($folders_by_id[$id]['parent_id']);
            $has_visible_parent = ($parent_id !== null && isset($folders_by_id[$parent_id]) && !$folders_by_id[$parent_id]['excluded']);

            if ($has_visible_parent && strpos($folders_by_id[$id]['folder_full_name'], '-noshow') !== false) {
                $folders_by_id[$id]['node_ref'] = $resolve_node_ref($parent_id);
                $folders_by_id[$id]['is_transparent'] = true;
                return $folders_by_id[$id]['node_ref'];
            }

            $folders_by_id[$id]['node_ref'] = 'stub:' . $id;
            return $folders_by_id[$id]['node_ref'];
        };

        foreach ($folders_by_id as $id => $f) {
            if ($f['excluded']) {
                continue;
            }
            $resolve_node_ref($id);
        }

        // Walk the folder tree top-down assigning parent_ref to every page,
        // skipping over excluded folders (their content has no WP parent to hang off).
        $roots = array();
        foreach ($folders_by_id as $id => $f) {
            $parent_id = self::norm_id($f['parent_id']);
            $has_visible_parent = ($parent_id !== null && isset($folders_by_id[$parent_id]) && !$folders_by_id[$parent_id]['excluded']);
            if (!$f['excluded'] && !$has_visible_parent) {
                $roots[] = $id;
            }
        }

        foreach ($folders_by_id as $id => $f) {
            if ($f['excluded']) {
                continue;
            }
            $parent_ref = 'root';
            $parent_id = self::norm_id($f['parent_id']);
            if ($parent_id !== null && isset($folders_by_id[$parent_id]) && !$folders_by_id[$parent_id]['excluded']) {
                $parent_ref = $folders_by_id[$parent_id]['node_ref'];
            }

            // The folder's node page (main_page or implicit index page)
            // attaches to the parent folder's node — except for a transparent
            // (-noshow) folder, whose node_ref is borrowed from its own
            // parent rather than a page it owns, so there's nothing of its
            // own to attach (that page's parent_ref was already set when its
            // actual owning folder was processed).
            if (!$f['is_transparent'] && strpos($f['node_ref'], 'page:') === 0) {
                $node_page_id = substr($f['node_ref'], 5);
                $pages_by_id[$node_page_id]['parent_ref'] = $parent_ref;
            }
            // stub: nodes don't need parent_ref stored here — the importer creates
            // the stub page itself and uses $parent_ref at that point (see get_stub()).

            // Every other page in the folder is a child of this folder's own node.
            foreach ($f['page_ids'] as $page_id) {
                if ($pages_by_id[$page_id]['is_folder_node']) {
                    continue; // that's the node page itself, handled above
                }
                $pages_by_id[$page_id]['parent_ref'] = $f['node_ref'];
            }
        }

        return array(
            'folders'                 => $folders_by_id,
            'pages'                   => $pages_by_id,
            'roots'                   => $roots,
            'vacancy_root_folder_ids' => $vacancy_root_folder_ids,
        );
    }

    /**
     * Recursively force-draft every page in a folder and its subfolders.
     */
    private static function mark_subtree_draft(&$folders_by_id, &$pages_by_id, $folder_id, $reason) {
        if (!isset($folders_by_id[$folder_id])) {
            return;
        }
        foreach ($folders_by_id[$folder_id]['page_ids'] as $page_id) {
            $pages_by_id[$page_id]['force_draft'] = true;
            $pages_by_id[$page_id]['draft_reasons'][] = $reason;
        }
        foreach ($folders_by_id[$folder_id]['children_folder_ids'] as $child_id) {
            self::mark_subtree_draft($folders_by_id, $pages_by_id, $child_id, $reason);
        }
    }

    /**
     * Recursively exclude a folder and everything nested under it from the
     * Pages tree (used to hand a whole subtree off to a dedicated importer).
     */
    private static function mark_subtree_excluded(&$folders_by_id, $folder_id, $reason) {
        if (!isset($folders_by_id[$folder_id]) || $folders_by_id[$folder_id]['excluded']) {
            return;
        }
        $folders_by_id[$folder_id]['excluded'] = true;
        $folders_by_id[$folder_id]['exclude_reason'] = $reason;
        foreach ($folders_by_id[$folder_id]['children_folder_ids'] as $child_id) {
            self::mark_subtree_excluded($folders_by_id, $child_id, $reason);
        }
    }

    /**
     * Build a ChurchEdit URL-path -> old page_id index, used to resolve hrefs
     * found inside "flexContainer" page-link card grids back to the source
     * page they pointed at (see CSI_Page_Links_Extractor).
     *
     * Two path variants are indexed per page/folder: the full folder chain,
     * and one with any `-noshow` folder segments flattened out — ChurchEdit
     * omits `-noshow` folders from a page's live URL (confirmed against real
     * hrefs in the export, e.g. a page physically inside
     * aboutus/publications/publications-noshow/ is linked to at
     * /aboutus/publications/<page>.php, no "publications-noshow" segment) but
     * some hrefs in the content still include it, so both forms are tried.
     *
     * @return array{pages: array<string,string>, folders: array<string,string>}
     *   'pages' maps a page path to its page_id. 'folders' maps a
     *   trailing-slash folder path to that folder's main_page page_id (for
     *   hrefs that link to a folder rather than a specific page).
     */
    public static function build_url_index($resolved) {
        $page_index   = array();
        $folder_index = array();

        foreach ($resolved['pages'] as $pid => $p) {
            $full_name = $p['page_full_name'];
            if (empty($full_name)) {
                continue;
            }
            $folder_id = self::norm_id($p['folder_id']);
            foreach (array(false, true) as $flatten) {
                $segments = $folder_id !== null ? self::folder_url_segments($resolved, $folder_id, $flatten) : array();
                $path = '/' . implode('/', array_merge($segments, array($full_name)));
                if (!isset($page_index[$path])) {
                    $page_index[$path] = (string) $pid;
                }
            }
        }

        foreach ($resolved['folders'] as $fid => $f) {
            if (!empty($f['excluded'])) {
                continue;
            }
            $main_page_id = self::norm_id($f['main_page']);
            if ($main_page_id === null || !isset($resolved['pages'][$main_page_id])) {
                // No explicit landing page — some folders instead hold a page
                // whose own filename matches the folder's name (e.g. folder
                // "exploring-your-vocation" containing
                // "exploring-your-vocation.php"), which ChurchEdit still
                // serves at the folder's own URL even though main_page was
                // never set. Fall back to that convention if present.
                $main_page_id = self::find_index_named_page($resolved['folders'], $resolved['pages'], $fid, $f['folder_full_name']);
            }
            if ($main_page_id === null) {
                continue;
            }
            foreach (array(false, true) as $flatten) {
                $segments = self::folder_url_segments($resolved, $fid, $flatten);
                $path = '/' . implode('/', $segments) . '/';
                if (!isset($folder_index[$path])) {
                    $folder_index[$path] = (string) $main_page_id;
                }
            }
        }

        return array('pages' => $page_index, 'folders' => $folder_index);
    }

    /**
     * Find a page directly inside $folder_id whose own filename (minus
     * .php) matches the folder's name — ChurchEdit's implicit "index page"
     * convention for folders that never got an explicit main_page set.
     */
    private static function find_index_named_page($folders_by_id, $pages_by_id, $folder_id, $folder_full_name) {
        if (!isset($folders_by_id[$folder_id])) {
            return null;
        }
        foreach ($folders_by_id[$folder_id]['page_ids'] as $pid) {
            if (!isset($pages_by_id[$pid])) {
                continue;
            }
            $name = $pages_by_id[$pid]['page_full_name'];
            $name_without_ext = preg_replace('/\.php$/i', '', (string) $name);
            if ($name_without_ext === $folder_full_name) {
                return (string) $pid;
            }
        }
        return null;
    }

    /**
     * Ordered list of folder_full_name segments from root down to $folder_id.
     * When $flatten_noshow is true, any folder whose name contains "-noshow"
     * is omitted from the path (matching ChurchEdit's live URL behavior).
     */
    private static function folder_url_segments($resolved, $folder_id, $flatten_noshow) {
        $segments = array();
        $seen = array();
        while ($folder_id !== null && !isset($seen[$folder_id])) {
            $seen[$folder_id] = true;
            if (!isset($resolved['folders'][$folder_id])) {
                break;
            }
            $f = $resolved['folders'][$folder_id];
            if (!$flatten_noshow || strpos($f['folder_full_name'], '-noshow') === false) {
                $segments[] = $f['folder_full_name'];
            }
            $folder_id = self::norm_id($f['parent_id']);
        }
        return array_reverse($segments);
    }

    /**
     * Normalize a raw folder_id/parent_id/main_page value: '0', '', NULL all mean "none".
     */
    private static function norm_id($value) {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        return (string) $value;
    }
}
