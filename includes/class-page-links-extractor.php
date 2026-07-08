<?php
/**
 * Page Links Extractor Class
 *
 * ChurchEdit's "card grid" links to other pages aren't reliably flagged by
 * the page_options JSON (see the hierarchy resolver's card_auto/card_review
 * flags — confirmed unreliable during migration planning). The actual,
 * reliable signal is structural: a `<div class="flexContainer">` wrapping one
 * or more `<blockquote class="landing ...">` cards, each linking to another
 * page. This class finds those, strips them out of the raw HTML (they're
 * replaced with a placeholder comment further down the pipeline — see
 * CSI_Importer::import_page()), and classifies each card's link as either an
 * internal page reference (resolved by URL path, see
 * CSI_Hierarchy_Resolver::build_url_index()) or an external-style link
 * (genuine external URL, a document, or anything that doesn't resolve).
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Page_Links_Extractor {

    /**
     * @param string $html      Raw page_content HTML
     * @param array  $url_index Output of CSI_Hierarchy_Resolver::build_url_index()
     * @return array{html: string, groups: array} $html has every flexContainer
     *   removed; $groups is a list (one per flexContainer found) of
     *   ['page_refs' => [...], 'external_links' => [...]]
     */
    public static function extract($html, $url_index) {
        $html = CSI_Content_Converter::fix_stray_escapes((string) $html);

        if (stripos($html, 'flexContainer') === false) {
            return array('html' => $html, 'groups' => array());
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        // Match case-insensitively and allow extra classes — the source has
        // inconsistent casing ("flexContainer" vs "flexcontainer") and some
        // instances carry additional classes alongside it.
        $candidates = $xpath->query('//div[@class]');
        $containers = array();
        foreach ($candidates as $candidate) {
            $classes = preg_split('/\s+/', strtolower(trim($candidate->getAttribute('class'))));
            if (in_array('flexcontainer', $classes, true)) {
                $containers[] = $candidate;
            }
        }

        $groups = array();
        $to_remove = array();

        foreach ($containers as $container) {
            $cards = self::extract_cards($container, $xpath, $url_index);
            if (!empty($cards['page_refs']) || !empty($cards['external_links'])) {
                $groups[] = $cards;
            }
            $to_remove[] = $container;
        }

        foreach ($to_remove as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }

        $out = '';
        foreach ($dom->childNodes as $node) {
            // Skip the processing-instruction node from the leading
            // <?xml encoding="UTF-8"> we prepend before loadHTML() — saveHTML()
            // re-emits it as a bare "<?xml >" that isn't caught by a simple
            // string replace and confuses strip_tags()-based consumers
            // (e.g. CSI_Importer::generate_excerpt()) into eating everything
            // after it.
            if ($node->nodeType === XML_PI_NODE) {
                continue;
            }
            $out .= $dom->saveHTML($node);
        }

        return array('html' => trim($out), 'groups' => $groups);
    }

    private static function extract_cards($container, $xpath, $url_index) {
        $page_refs      = array();
        $external_links = array();

        $blockquotes = $xpath->query('.//blockquote[contains(concat(" ", normalize-space(@class), " "), " landing ")]', $container);

        foreach ($blockquotes as $bq) {
            $title = '';
            $href  = '';

            $h2s = $xpath->query('.//h2', $bq);
            if ($h2s->length > 0) {
                $title = trim($h2s->item(0)->textContent);
                $links_in_h2 = $xpath->query('.//a[@href]', $h2s->item(0));
                if ($links_in_h2->length > 0) {
                    $href = trim($links_in_h2->item(0)->getAttribute('href'));
                }
            }

            if ($href === '') {
                // No link on the heading itself — fall back to the card's
                // "Read More"-style link, if any.
                $any_links = $xpath->query('.//a[@href]', $bq);
                if ($any_links->length > 0) {
                    $href = trim($any_links->item(0)->getAttribute('href'));
                }
            }

            if ($title === '' || $href === '' || $href === '/') {
                // No usable title, or a dummy "/" placeholder link (seen in
                // ChurchEdit's own template/demo pages) — nothing to import.
                continue;
            }

            $excerpt = '';
            $summary_p = $xpath->query('.//p[contains(concat(" ", normalize-space(@class), " "), " boxpad ")]', $bq);
            if ($summary_p->length > 0) {
                $excerpt = self::truncate_words(wp_strip_all_tags($summary_p->item(0)->textContent), 20);
            }

            $old_page_id = self::resolve_href($href, $url_index);

            if ($old_page_id !== null) {
                $page_refs[] = array(
                    'old_page_id'      => $old_page_id,
                    'fallback_title'   => $title,
                    'fallback_url'     => $href,
                    'fallback_excerpt' => $excerpt,
                );
            } else {
                $external_links[] = array(
                    'url'     => $href,
                    'title'   => $title,
                    'excerpt' => $excerpt,
                );
            }
        }

        return array('page_refs' => $page_refs, 'external_links' => $external_links);
    }

    /**
     * Resolve an href to an old ChurchEdit page_id via the URL-path index,
     * trying both an exact page-path match and a trailing-slash folder match
     * (a link to a folder resolves to that folder's landing page).
     */
    private static function resolve_href($href, $url_index) {
        $path = $href;
        if (preg_match('#^https?://#i', $path)) {
            $parsed = wp_parse_url($path);
            $path = isset($parsed['path']) ? $parsed['path'] : '';
        }
        if ($path === '') {
            return null;
        }
        $path = preg_replace('/[?#].*$/', '', $path);

        if (isset($url_index['pages'][$path])) {
            return $url_index['pages'][$path];
        }

        $folder_path = (substr($path, -1) === '/') ? $path : ($path . '/');
        if (isset($url_index['folders'][$folder_path])) {
            return $url_index['folders'][$folder_path];
        }

        return null;
    }

    private static function truncate_words($text, $max_words) {
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
}
