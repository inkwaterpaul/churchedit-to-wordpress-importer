<?php
/**
 * Content Converter Class
 *
 * Converts a ChurchEdit `page_content` HTML string into WordPress block markup.
 * Adapted from page-importer-from-html's PI_Content_Extractor::convert_to_blocks(),
 * which already special-cases the exact `<table align="CENTER">` layout-wrapper
 * pattern ChurchEdit's export uses — reused here operating on a string instead of a file.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Content_Converter {

    /**
     * Convert a raw ChurchEdit page_content HTML string to WP block markup.
     *
     * @param string $html Raw page_content
     * @return string Block markup
     */
    public static function convert($html) {
        if (trim((string) $html) === '') {
            return '';
        }

        $html      = self::fix_stray_escapes($html);
        $html      = self::decode_obfuscated_emails($html);
        $unwrapped = self::unwrap_layout_tables($html);
        $cleaned   = self::clean_html($unwrapped);
        return self::convert_to_blocks($cleaned);
    }

    /**
     * ChurchEdit obfuscates mailto links against spam harvesters as
     * `javascript:void(location.href='mailto:'+String.fromCharCode(...))`,
     * optionally with a `+'?subject=...'` (and/or `&body=...`) suffix appended
     * as a plain string. Decode the char codes back into a real address and
     * rebuild a normal mailto: link — WordPress content doesn't need (or
     * support executing) this kind of obfuscation, and left as-is it shows up
     * as a dead "javascript:void(...)" link with no visible email anywhere.
     */
    private static function decode_obfuscated_emails($html) {
        return preg_replace_callback(
            // The optional "+'...'" suffix (?subject=.../&body=...) is matched
            // as "anything but a double-quote" rather than "anything but a
            // single-quote" — a couple of real subject lines contain their
            // own unescaped apostrophe (already broken on the live site too,
            // since that's invalid inside a single-quoted JS string), and this
            // stays correctly bounded to the current attribute either way,
            // since a literal double-quote can't appear inside it.
            '/href="javascript:void\(location\.href=\'mailto:\'\+String\.fromCharCode\(([\d,\s]+)\)(\+\'[^"]*\')?\)"/i',
            function ($m) {
                $codes = array_map('intval', explode(',', $m[1]));
                $email = implode('', array_map('chr', $codes));

                $extra = '';
                if (!empty($m[2])) {
                    $extra = substr($m[2], 2, -1); // strip the leading +' and trailing '
                }

                return 'href="mailto:' . $email . $extra . '"';
            },
            $html
        );
    }

    /**
     * ChurchEdit's page_content has a longstanding data quirk (predates this
     * import — visible even in the raw mysqldump) where attribute quotes ended
     * up double-escaped, e.g. align=\"CENTER\" or a src that decodes to
     * .../file.jpeg%5C%22. Literal backslash-quote sequences are never valid
     * HTML, so stripping them is always correct, not a guess.
     */
    public static function fix_stray_escapes($html) {
        return str_replace(array('\\"', "\\'"), array('"', "'"), $html);
    }

    /**
     * ChurchEdit wraps page content in a `<table align="CENTER">` (or similar)
     * layout table. Unwrap any such direct top-level layout tables, concatenating
     * their cell contents, before block conversion — otherwise every page would
     * become a single wp:table block.
     */
    private static function unwrap_layout_tables($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $out = '';
        foreach ($dom->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'table' && self::is_layout_table($child)) {
                $out .= self::extract_table_content($child, $dom);
            } else {
                $out .= $dom->saveHTML($child);
            }
        }
        return $out;
    }

    /**
     * A "layout" table has no <th> and isn't tabular data — ChurchEdit uses these
     * purely to center/pad the page body. Tables with real data are left alone
     * so they still become a proper wp:table block.
     */
    private static function is_layout_table($table) {
        if ($table->getElementsByTagName('th')->length > 0) {
            return false;
        }
        // Count only this table's own rows, not descendants — ChurchEdit content
        // often nests a real (or another layout) table inside a cell, and
        // getElementsByTagName() would otherwise count those rows too.
        $row_count = 0;
        foreach ($table->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'tbody') {
                foreach ($child->childNodes as $grandchild) {
                    if ($grandchild instanceof DOMElement && strtolower($grandchild->tagName) === 'tr') {
                        $row_count++;
                    }
                }
            } elseif ($child instanceof DOMElement && strtolower($child->tagName) === 'tr') {
                $row_count++;
            }
        }
        // A genuine data table usually has more than one row; layout tables are
        // almost always a single wrapper row/cell.
        return $row_count <= 1;
    }

    private static function extract_table_content($table, $dom) {
        $html = '';
        $cells = $table->getElementsByTagName('td');
        foreach ($cells as $cell) {
            foreach ($cell->childNodes as $child) {
                $html .= $dom->saveHTML($child);
            }
        }
        return $html;
    }

    /**
     * Classes that identify structural ChurchEdit components (currently just
     * its accordion markup) rather than old theme styling — clean_html()
     * keeps only these out of any @class it finds, so later passes (see
     * is_accordion_component()) can still recognise the structure once every
     * other legacy class/style attribute has been stripped.
     */
    private static $preserved_classes = array('accordion', 'accordion-row', 'accordion-row-title', 'accordion-row-body');

    /**
     * Strip inline styles/classes/legacy attributes that were tied to
     * ChurchEdit's old theme CSS and won't mean anything in the new theme.
     */
    private static function clean_html($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//*[@style or @bordercolor or @cellpadding or @cellspacing or @valign or @align or @class]');
        foreach ($elements as $element) {
            $element->removeAttribute('style');
            $element->removeAttribute('bordercolor');
            $element->removeAttribute('cellpadding');
            $element->removeAttribute('cellspacing');
            $element->removeAttribute('valign');
            $element->removeAttribute('align');

            if ($element->hasAttribute('class')) {
                $kept = array_intersect(
                    preg_split('/\s+/', trim($element->getAttribute('class'))),
                    self::$preserved_classes
                );
                if (!empty($kept)) {
                    $element->setAttribute('class', implode(' ', $kept));
                } else {
                    $element->removeAttribute('class');
                }
            }
        }

        $cleaned = $dom->saveHTML();
        $cleaned = preg_replace('/^<!DOCTYPE.+?>/', '', str_replace(
            array('<?xml encoding="UTF-8">', '<html>', '</html>', '<body>', '</body>'),
            '',
            $cleaned
        ));

        return trim($cleaned);
    }

    private static function convert_to_blocks($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $blocks = self::nodes_to_blocks($dom->childNodes);

        $blocks = str_replace(array('encoding="UTF-8"', '<?xml encoding="UTF-8"?>'), '', $blocks);
        return trim($blocks);
    }

    /**
     * Convert a list of sibling DOM nodes to block markup, node by node —
     * except that a run of consecutive ChurchEdit accordion components
     * (`.accordion` wrappers and/or bare `.accordion-row`s, however they're
     * nested or interspersed with blank whitespace) is collapsed into a
     * single wp:accordion block instead of one wp:group per div. ChurchEdit's
     * export is inconsistent about whether an `.accordion` wraps one row or
     * several, or whether the wrapper is there at all, so grouping is done
     * structurally here rather than assumed from a single node shape.
     */
    private static function nodes_to_blocks($node_list) {
        $children = array();
        foreach ($node_list as $child) {
            $children[] = $child;
        }

        $blocks = '';
        $count  = count($children);
        $i      = 0;

        while ($i < $count) {
            $node = $children[$i];

            if ($node instanceof DOMElement && self::is_accordion_component($node)) {
                $rows = array();
                while ($i < $count) {
                    $candidate = $children[$i];
                    if ($candidate instanceof DOMElement && self::is_accordion_component($candidate)) {
                        $rows = array_merge($rows, self::extract_accordion_rows($candidate));
                        $i++;
                    } elseif (self::is_insignificant_text($candidate)) {
                        $i++;
                    } else {
                        break;
                    }
                }
                $blocks .= self::build_accordion_block($rows);
                continue;
            }

            $blocks .= self::node_to_block($node);
            $i++;
        }

        return $blocks;
    }

    /**
     * Whitespace-only text (including a lone "&nbsp;") between accordion
     * components — ChurchEdit pads each one with a blank line — that
     * shouldn't break up a run of rows into separate accordion blocks.
     */
    private static function is_insignificant_text($node) {
        if (!($node instanceof DOMText || $node instanceof DOMComment)) {
            return false;
        }
        return trim(str_replace("\xC2\xA0", ' ', $node->textContent)) === '';
    }

    private static function is_accordion_component($node) {
        return self::has_class($node, 'accordion') || self::has_class($node, 'accordion-row');
    }

    private static function has_class($node, $class) {
        if (!($node instanceof DOMElement) || !$node->hasAttribute('class')) {
            return false;
        }
        return in_array($class, preg_split('/\s+/', trim($node->getAttribute('class'))), true);
    }

    /**
     * A `.accordion-row` is a single row and is returned as-is; a `.accordion`
     * wrapper is expanded to whichever `.accordion-row`s it contains (one, in
     * most of ChurchEdit's export, but never assumed).
     */
    private static function extract_accordion_rows($node) {
        if (self::has_class($node, 'accordion-row')) {
            return array($node);
        }
        return self::find_all_by_class($node, 'accordion-row');
    }

    /**
     * First descendant (depth-first) carrying $class, or null.
     */
    private static function find_first_by_class($node, $class) {
        foreach ($node->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            if (self::has_class($child, $class)) {
                return $child;
            }
            $found = self::find_first_by_class($child, $class);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    /**
     * Every descendant carrying $class — doesn't recurse into a match, since
     * ChurchEdit never nests one `.accordion-row` inside another.
     */
    private static function find_all_by_class($node, $class) {
        $results = array();
        foreach ($node->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            if (self::has_class($child, $class)) {
                $results[] = $child;
            } else {
                $results = array_merge($results, self::find_all_by_class($child, $class));
            }
        }
        return $results;
    }

    /**
     * Concatenated outerHTML of $node's children.
     */
    private static function inner_html($node) {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    /**
     * Build a wp:accordion block (core/accordion + accordion-item/heading/
     * panel — the real WP core blocks, matching their save() output exactly
     * so the block editor parses them as valid rather than "needs fixing")
     * from a flat list of `.accordion-row` elements.
     */
    private static function build_accordion_block($rows) {
        $items = '';
        foreach ($rows as $row) {
            $items .= self::build_accordion_item($row);
        }
        if (trim($items) === '') {
            return '';
        }
        return "<!-- wp:accordion -->\n<div class=\"wp-block-accordion\" role=\"group\">\n" . $items . "</div>\n<!-- /wp:accordion -->\n\n";
    }

    private static function build_accordion_item($row) {
        $title_node = self::find_first_by_class($row, 'accordion-row-title');
        $title_html = $title_node ? trim(self::inner_html($title_node)) : '';

        $body_node   = self::find_first_by_class($row, 'accordion-row-body');
        $body_blocks = $body_node ? self::nodes_to_blocks($body_node->childNodes) : '';
        if (trim($body_blocks) === '') {
            $body_blocks = "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->\n\n";
        }

        $heading = "<!-- wp:accordion-heading -->\n"
            . "<h3 class=\"wp-block-accordion-heading has-icon has-icon-right\"><button type=\"button\" class=\"wp-block-accordion-heading__toggle\">"
            . "<span class=\"wp-block-accordion-heading__toggle-title\">" . $title_html . "</span>"
            . "<span class=\"wp-block-accordion-heading__toggle-icon\" aria-hidden=\"true\">+</span>"
            . "</button></h3>\n<!-- /wp:accordion-heading -->\n\n";

        $panel = "<!-- wp:accordion-panel -->\n<div class=\"wp-block-accordion-panel\" role=\"region\">\n" . $body_blocks . "</div>\n<!-- /wp:accordion-panel -->\n\n";

        return "<!-- wp:accordion-item -->\n<div class=\"wp-block-accordion-item\">\n" . $heading . $panel . "</div>\n<!-- /wp:accordion-item -->\n\n";
    }

    private static function node_to_block($node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) === '') {
                return '';
            }
            return $node->textContent;
        }

        $tag  = strtolower($node->nodeName);
        $html = $node->ownerDocument->saveHTML($node);

        switch ($tag) {
            case 'p':
                if (trim($node->textContent) === '' && !self::has_embedded_content($node)) {
                    return '';
                }
                // ChurchEdit content commonly puts a lone (optionally
                // linked) image inside a <p> rather than a <figure>. Left as
                // a wp:paragraph, the image stays raw markup inside a text
                // block instead of a real, editable image block — so it's
                // special-cased the same way the single-image <table> below
                // already is.
                $sole_image = self::get_sole_image($node);
                if ($sole_image) {
                    $figure_content = $sole_image;
                    if ($sole_image->parentNode instanceof DOMElement && strtolower($sole_image->parentNode->nodeName) === 'a') {
                        $figure_content = $sole_image->parentNode;
                    }
                    $img_html = $node->ownerDocument->saveHTML($figure_content);
                    return "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $img_html . "</figure>\n<!-- /wp:image -->\n\n";
                }
                return "<!-- wp:paragraph -->\n" . $html . "\n<!-- /wp:paragraph -->\n\n";

            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $level = substr($tag, 1);
                return "<!-- wp:heading {\"level\":" . $level . "} -->\n" . $html . "\n<!-- /wp:heading -->\n\n";

            case 'img':
                return "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $html . "</figure>\n<!-- /wp:image -->\n\n";

            case 'ul':
                return "<!-- wp:list -->\n" . $html . "\n<!-- /wp:list -->\n\n";

            case 'ol':
                return "<!-- wp:list {\"ordered\":true} -->\n" . $html . "\n<!-- /wp:list -->\n\n";

            case 'blockquote':
                $has_block_children = false;
                foreach ($node->childNodes as $bqChild) {
                    if ($bqChild->nodeType === XML_ELEMENT_NODE &&
                        in_array(strtolower($bqChild->nodeName), array('div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'figure', 'ul', 'ol', 'table'), true)) {
                        $has_block_children = true;
                        break;
                    }
                }
                if ($has_block_children) {
                    return self::nodes_to_blocks($node->childNodes);
                }
                return "<!-- wp:quote -->\n" . $html . "\n<!-- /wp:quote -->\n\n";

            case 'pre':
            case 'code':
                return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . htmlspecialchars($node->textContent) . "</code></pre>\n<!-- /wp:code -->\n\n";

            case 'table':
                $table_images = $node->getElementsByTagName('img');
                $table_text   = trim($node->textContent);
                if ($table_images->length === 1 && $table_text === '') {
                    $img_html = $node->ownerDocument->saveHTML($table_images->item(0));
                    return "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $img_html . "</figure>\n<!-- /wp:image -->\n\n";
                }
                return "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . $html . "</figure>\n<!-- /wp:table -->\n\n";

            case 'hr':
                return "<!-- wp:separator -->\n" . $html . "\n<!-- /wp:separator -->\n\n";

            case 'figure':
                if ($node->getElementsByTagName('img')->length > 0) {
                    return "<!-- wp:image -->\n" . $html . "\n<!-- /wp:image -->\n\n";
                }
                return $html . "\n\n";

            case 'div':
            case 'section':
            case 'article':
                $content = self::nodes_to_blocks($node->childNodes);
                if (trim($content) !== '') {
                    return "<!-- wp:group -->\n<div class=\"wp-block-group\">" . $content . "</div>\n<!-- /wp:group -->\n\n";
                }
                return '';

            default:
                return self::nodes_to_blocks($node->childNodes);
        }
    }

    /**
     * Whether a text-empty <p> still carries meaningful embedded content
     * (image, iframe embed, video/audio, form) that must not be dropped.
     */
    private static function has_embedded_content($node) {
        foreach (array('img', 'iframe', 'video', 'audio', 'embed', 'object', 'form') as $tag) {
            if ($node->getElementsByTagName($tag)->length > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The node's single <img>, if it has no other text — same "one image,
     * nothing else" test already used for the <table> case below.
     */
    private static function get_sole_image($node) {
        if (trim($node->textContent) !== '') {
            return null;
        }
        $imgs = $node->getElementsByTagName('img');
        if ($imgs->length !== 1) {
            return null;
        }
        return $imgs->item(0);
    }

    /**
     * Extract every non-absolute <img src> and document <a href> in the given
     * (already-converted) block content, mirroring PI_Importer::save_pending_media.
     *
     * @return array{images: array, docs: array}
     */
    public static function extract_pending_media($content) {
        $pending_images = array();
        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $matches);
        foreach ($matches[1] as $src) {
            if (preg_match('#^https?://#', $src)) {
                continue;
            }
            $filename = basename(preg_replace('/\?.*$/', '', urldecode($src)));
            if ($filename) {
                $pending_images[] = array('filename' => $filename, 'original_src' => $src);
            }
        }

        $doc_ext = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'csv');
        $pending_docs = array();
        preg_match_all('/href="([^"]+\.(' . implode('|', $doc_ext) . '))"/i', $content, $matches);
        foreach ($matches[1] as $href) {
            if (preg_match('#^https?://#', $href)) {
                continue;
            }
            $filename = basename(preg_replace('/\?.*$/', '', urldecode($href)));
            if ($filename) {
                $pending_docs[] = array('filename' => $filename, 'original_href' => $href);
            }
        }

        return array('images' => $pending_images, 'docs' => $pending_docs);
    }
}
