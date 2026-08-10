<?php
/**
 * Vacancy Importer Class
 *
 * ChurchEdit's real vacancy content lives as regular `pages` under a
 * "vacancieslist"-type folder, with each direct child folder acting as a
 * category (e.g. "Diocesan Roles", "Full Time Stipendiary") — not via the
 * XDB_vacancies2951 table, which in this export only holds one unused test row.
 * Imports each page as a 'vacancy' post (registered by the octopus-vacancies
 * mu-plugin) and assigns the matching 'vacancy-category' term.
 *
 * Note: ChurchEdit's `pages` table has no per-vacancy closing-date field, so
 * the octopus-vacancies closing-date meta (_octopus_vacancy_has_deadline etc.)
 * is left unset — there's no source data to populate it from.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Vacancy_Importer {

    /**
     * Build the list of pages to import as vacancies from a resolved root
     * folder: pages directly in the root have no category; pages in each
     * child folder (recursively) are tagged with that child folder's name.
     *
     * @return array List of ['page_id'=>, 'category'=>string|null]
     */
    public static function get_vacancy_items($resolved, $root_folder_id) {
        $items = array();
        $root = $resolved['folders'][$root_folder_id];

        foreach ($root['page_ids'] as $page_id) {
            $items[] = array('page_id' => $page_id, 'category' => null);
        }

        foreach ($root['children_folder_ids'] as $child_id) {
            $child = $resolved['folders'][$child_id];
            $category_name = $child['folder_name'] ? trim($child['folder_name']) : $child['folder_full_name'];
            self::collect_pages($resolved, $child_id, $category_name, $items);
        }

        return $items;
    }

    private static function collect_pages($resolved, $folder_id, $category_name, &$items) {
        $folder = $resolved['folders'][$folder_id];
        foreach ($folder['page_ids'] as $page_id) {
            $items[] = array('page_id' => $page_id, 'category' => $category_name);
        }
        foreach ($folder['children_folder_ids'] as $child_id) {
            self::collect_pages($resolved, $child_id, $category_name, $items);
        }
    }

    /**
     * @param array $page     One row from the `pages` table
     * @param string|null $category_name
     * @param array $options  ['default_status' => 'draft'|'publish'|'pending']
     * @return array Result, same shape as CSI_Importer::import_item()
     */
    public static function import_vacancy($page, $category_name, $options = array()) {
        try {
            if (!post_type_exists('vacancy')) {
                return array('ref' => 'vacancy:' . $page['page_id'], 'success' => false, 'error' => 'The "vacancy" post type is not registered (octopus-vacancies plugin not active).');
            }

            $page_id = $page['page_id'];
            $ref = 'vacancy:' . $page_id;

            // The block layout pattern (sidebar page-navigation wrapper) is a
            // Pages-only concept — Vacancies don't use it.
            $body    = CSI_Content_Converter::convert($page['page_content']);
            // The 'vacancy' post type's registered block template starts with
            // octopus/vacancy-closing-date. wp_insert_post() doesn't apply
            // registered templates to programmatically-created posts, so it's
            // added explicitly here. Its attributes are meta-sourced (see
            // block.json), so the bare self-closing comment is enough — the
            // actual values come from the postmeta set below.
            $content = "<!-- wp:octopus/vacancy-closing-date /-->\n\n" . $body;
            $status  = (!empty($page['page_access']) && $page['page_access'] !== 'everyone') ? 'draft' : $options['default_status'];

            $closing_date = self::extract_closing_date($page['page_content']);

            $existing = self::find_existing_post($ref);

            $post_data = array(
                'post_title'   => wp_strip_all_tags($page['page_title']),
                'post_content' => $content,
                'post_excerpt' => !empty($page['page_summary']) ? wp_strip_all_tags($page['page_summary']) : '',
                'post_status'  => $status,
                'post_type'    => 'vacancy',
            );

            // See CSI_Post_Importer::import_post() for why this is dropped
            // rather than set on a targeted-update pass.
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

            if (!empty($category_name)) {
                $term_id = self::ensure_category_term($category_name);
                if ($term_id) {
                    wp_set_object_terms($post_id, array($term_id), 'vacancy-category');
                }
            }

            update_post_meta($post_id, '_ce_source_ref', $ref);
            update_post_meta($post_id, '_ce_page_id', $page_id);
            update_post_meta($post_id, '_ce_folder_id', $page['folder_id']);

            if ($closing_date) {
                update_post_meta($post_id, '_octopus_vacancy_closing_date', $closing_date['date_string']);
                update_post_meta($post_id, '_octopus_vacancy_date_timestamp', $closing_date['timestamp_ms']);
                update_post_meta($post_id, '_octopus_vacancy_has_deadline', true);
            } else {
                delete_post_meta($post_id, '_octopus_vacancy_closing_date');
                delete_post_meta($post_id, '_octopus_vacancy_date_timestamp');
                update_post_meta($post_id, '_octopus_vacancy_has_deadline', false);
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
                'ref'          => $ref,
                'post_id'      => $post_id,
                'title'        => $page['page_title'],
                'category'     => $category_name,
                'closing_date' => $closing_date ? $closing_date['date_string'] : null,
                'action'       => $action,
                'success'      => true,
                'draft'        => ($status === 'draft'),
            );
        } catch (Exception $e) {
            return array('ref' => 'vacancy:' . $page['page_id'], 'success' => false, 'error' => 'Exception: ' . $e->getMessage());
        } catch (Error $e) {
            return array('ref' => 'vacancy:' . $page['page_id'], 'success' => false, 'error' => 'Fatal error: ' . $e->getMessage());
        }
    }

    /**
     * Vacancy closing dates in the source content aren't consistently
     * labelled, so this looks for a recognizable date within a short window
     * of a deadline-related keyword ("closing date", "deadline", "apply by",
     * etc.) rather than guessing from any date found anywhere in the text —
     * a page's publish date, an event date, or similar would otherwise get
     * misread as the closing date. Returns null (no deadline set) if nothing
     * is found near a keyword, rather than falling back to a random guess.
     *
     * Named-month dates ("12 January 2026") are tried first since they're
     * unambiguous; numeric dates (12/01/2026) are parsed explicitly as
     * UK-style day/month/year rather than trusting PHP's DateTime, which
     * interprets slash-separated dates as US month/day/year.
     *
     * @return array{date_string: string, timestamp_ms: int}|null
     */
    private static function extract_closing_date($html) {
        $text = wp_strip_all_tags((string) $html);
        $text = preg_replace('/\s+/', ' ', $text);

        $keyword_pattern = '/\b(?:closing\s*date|clos(?:e|es|ed|ing)\s*(?:on|date|by)?|deadline|apply\s*by|applications?\s*(?:close|closes|deadline|by)|last\s*date|received\s*by)\b/i';

        if (!preg_match_all($keyword_pattern, $text, $kw_matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $named_month_pattern = '/\b(\d{1,2})(?:st|nd|rd|th)?\s+(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sept?|Oct|Nov|Dec)\.?\s+(\d{4})\b/i';
        $numeric_pattern     = '/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/';

        foreach ($kw_matches[0] as $kw_match) {
            $kw_start = $kw_match[1];
            $kw_end   = $kw_start + strlen($kw_match[0]);

            $window_start = max(0, $kw_start - 40);
            $window       = substr($text, $window_start, ($kw_end - $window_start) + 80);

            if (preg_match($named_month_pattern, $window, $m)) {
                try {
                    $dt = new DateTime($m[1] . ' ' . $m[2] . ' ' . $m[3]);
                    return array('date_string' => $dt->format('j F Y'), 'timestamp_ms' => $dt->getTimestamp() * 1000);
                } catch (Exception $e) {
                    // fall through to numeric attempt below
                }
            }

            if (preg_match($numeric_pattern, $window, $m)) {
                $day   = (int) $m[1];
                $month = (int) $m[2];
                $year  = (int) $m[3];
                if ($year < 100) {
                    $year += 2000;
                }
                if (checkdate($month, $day, $year)) {
                    $dt = new DateTime();
                    $dt->setDate($year, $month, $day)->setTime(0, 0, 0);
                    return array('date_string' => $dt->format('j F Y'), 'timestamp_ms' => $dt->getTimestamp() * 1000);
                }
            }
        }

        return null;
    }

    private static function ensure_category_term($name) {
        $term = get_term_by('name', $name, 'vacancy-category');
        if ($term) {
            return (int) $term->term_id;
        }
        $result = wp_insert_term($name, 'vacancy-category');
        if (is_wp_error($result)) {
            return 0;
        }
        return (int) $result['term_id'];
    }

    private static function find_existing_post($ref) {
        $existing = get_posts(array(
            'post_type'      => 'vacancy',
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
