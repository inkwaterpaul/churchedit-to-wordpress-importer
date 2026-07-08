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
            $element->removeAttribute('class');
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

        $blocks = '';
        foreach ($dom->childNodes as $node) {
            $blocks .= self::node_to_block($node);
        }

        $blocks = str_replace(array('encoding="UTF-8"', '<?xml encoding="UTF-8"?>'), '', $blocks);
        return trim($blocks);
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
                    $content = '';
                    foreach ($node->childNodes as $child) {
                        $content .= self::node_to_block($child);
                    }
                    return $content;
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
                $content = '';
                foreach ($node->childNodes as $child) {
                    $content .= self::node_to_block($child);
                }
                if (trim($content) !== '') {
                    return "<!-- wp:group -->\n<div class=\"wp-block-group\">" . $content . "</div>\n<!-- /wp:group -->\n\n";
                }
                return '';

            default:
                $content = '';
                foreach ($node->childNodes as $child) {
                    $content .= self::node_to_block($child);
                }
                return $content;
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
