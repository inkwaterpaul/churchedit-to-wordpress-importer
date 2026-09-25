<?php
/**
 * Change List Class
 * Turns "what changed between two ChurchEdit exports" into a readable to-do
 * list for updating a WordPress page by hand.
 *
 * Pages get tidied and restructured in WordPress after import (custom blocks,
 * reformatted headings, split paragraphs…), so their markup can't be relied
 * on to match anything the importer would generate. The comparison is done
 * on visible text and linked files instead: each changed line of the source
 * is checked against the page's current text to say whether it's already
 * been done, still needs doing, or needs looking at by hand — and every
 * document/image a new line links to is looked up in the Media Library.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Change_List {

    const FILE_EXTENSIONS = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp');

    /**
     * @param string   $old_html Source HTML from the old export
     * @param string   $new_html Source HTML from the new export
     * @param int|null $post_id  The WordPress post it was imported to, if any
     * @return array List of items: [
     *   'type'   => 'add'|'remove'|'change',
     *   'status' => 'done'|'todo'|'check',
     *   'old'    => lines removed/replaced, 'new' => lines added/replacing them,
     *   'after'  => text of the unchanged line before it (where to look), or '',
     * ] where a line is ['text' => string, 'links' => [['href','text','filename','is_file','media_url']...]]
     */
    public static function build($old_html, $new_html, $post_id) {
        $old_lines = self::lines($old_html);
        $new_lines = self::lines($new_html);

        $content  = $post_id ? (string) get_post_field('post_content', $post_id, 'raw') : '';
        $haystack = self::haystack($content);

        $old_keys = array_map(array(__CLASS__, 'line_key'), $old_lines);
        $new_keys = array_map(array(__CLASS__, 'line_key'), $new_lines);

        $items  = array();
        $prev_o = 0;
        $prev_n = 0;
        $pairs  = self::lcs_pairs($old_keys, $new_keys);
        $pairs[] = array(count($old_keys), count($new_keys)); // sentinel
        foreach ($pairs as $pair) {
            $removed = array_slice($old_lines, $prev_o, $pair[0] - $prev_o);
            $added   = array_slice($new_lines, $prev_n, $pair[1] - $prev_n);
            if ($removed || $added) {
                $after = $prev_o > 0 ? $old_lines[$prev_o - 1]['text'] : '';
                // Lines replaced one-for-one are shown as was/now pairs (a
                // swapped link, a reworded sentence); whatever's left over
                // is one "add these lines"/"remove these lines" item, so a
                // whole new or deleted section is a single to-do.
                $paired = min(count($removed), count($added));
                for ($i = 0; $i < $paired; $i++) {
                    $items[] = self::item(array($removed[$i]), array($added[$i]), $after, $haystack);
                }
                if (count($removed) > $paired) {
                    $items[] = self::item(array_slice($removed, $paired), array(), $after, $haystack);
                }
                if (count($added) > $paired) {
                    $items[] = self::item(array(), array_slice($added, $paired), $after, $haystack);
                }
            }
            $prev_o = $pair[0] + 1;
            $prev_n = $pair[1] + 1;
        }

        return $items;
    }

    /**
     * @param array $old Lines removed/replaced (may be empty)
     * @param array $new Lines added/replacing them (may be empty)
     */
    private static function item($old, $new, $after, $haystack) {
        $type = $old && $new ? 'change' : ($new ? 'add' : 'remove');

        $old_on_page = self::count_on_page($old, $haystack);
        $new_on_page = self::count_on_page($new, $haystack);

        if ($type === 'remove') {
            $status = $old_on_page ? 'todo' : 'done';
        } elseif ($new_on_page === count($new)) {
            $status = 'done';
        } elseif ($type === 'add' || $old_on_page) {
            $status = 'todo';
        } else {
            // A change whose old text isn't on the page any more — reworded
            // or restructured in WordPress, so it can't be pointed at.
            $status = 'check';
        }

        foreach ($new as $i => $line) {
            $new[$i]['links'] = array_map(array(__CLASS__, 'with_media'), $line['links']);
        }

        return array('type' => $type, 'status' => $status, 'old' => $old, 'new' => $new, 'after' => $after);
    }

    private static function count_on_page($lines, $haystack) {
        $count = 0;
        foreach ($lines as $line) {
            if (self::on_page($line, $haystack)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Every document/image a line links to, so it can be looked up (or
     * fetched) — only ChurchEdit-hosted files; links to other websites are
     * left alone.
     */
    public static function files_in($items) {
        $files = array();
        foreach ($items as $item) {
            foreach ($item['new'] as $line) {
                foreach ($line['links'] as $link) {
                    if ($link['is_file']) {
                        $files[$link['filename']] = $link;
                    }
                }
            }
        }
        return array_values($files);
    }

    private static function with_media($link) {
        $link['media_url'] = null;
        if ($link['is_file']) {
            $attachment_id = CSI_Media_Linker::find_attachment_by_filename($link['filename']);
            if ($attachment_id) {
                $link['media_url'] = wp_get_attachment_url($attachment_id);
            }
        }
        return $link;
    }

    /**
     * Whether a ChurchEdit href points at one of its own uploaded files
     * (relative /content/… path, or its CDN/site copy of one) rather than
     * a page or another website.
     */
    public static function is_churchedit_file($href) {
        $path = (string) wp_parse_url($href, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::FILE_EXTENSIONS, true) || strpos($path, '/content/') === false) {
            return false;
        }
        $host = (string) wp_parse_url($href, PHP_URL_HOST);
        if ($host === '') {
            return true;
        }
        $source_host = (string) wp_parse_url(self::source_site_url(), PHP_URL_HOST);
        return substr($host, -strlen('cloudfront.net')) === 'cloudfront.net'
            || ($source_host !== '' && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $source_host));
    }

    /**
     * The old ChurchEdit site's address (e.g. https://www.salisbury.anglican.org),
     * which relative /content/… file links are downloaded from — it
     * redirects them to wherever ChurchEdit actually hosts the file.
     */
    public static function source_site_url() {
        return untrailingslashit((string) get_option('csi_source_site_url', ''));
    }

    /**
     * Absolute URL a ChurchEdit file link can be downloaded from, or null if
     * it's relative and no source site has been set.
     */
    public static function download_url_for($href) {
        if (wp_parse_url($href, PHP_URL_HOST)) {
            return $href;
        }
        $base = self::source_site_url();
        return $base === '' ? null : $base . '/' . ltrim($href, '/');
    }

    /**
     * Visible text of some HTML as a list of lines (one per paragraph, list
     * item, heading, table cell, line break…), each with the links/images
     * in it. Used for source HTML and WordPress content alike.
     */
    public static function lines($html) {
        $html = CSI_Content_Converter::decode_obfuscated_emails(CSI_Content_Converter::fix_stray_escapes((string) $html));
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('#<(br|hr)\b[^>]*>|</?(p|li|h[1-6]|div|tr|td|th|blockquote|figure|figcaption|ul|ol|table)\b[^>]*>#i', "\n", $html);

        $lines = array();
        foreach (explode("\n", $html) as $segment) {
            $links = array();
            if (preg_match_all('#<a\b[^>]*\bhref=["\']([^"\']*)["\'][^>]*>(.*?)</a>#is', $segment, $m, PREG_SET_ORDER)) {
                foreach ($m as $a) {
                    $links[] = self::link(html_entity_decode($a[1], ENT_QUOTES, 'UTF-8'), self::clean_text($a[2]));
                }
            }
            if (preg_match_all('#<img\b[^>]*\bsrc=["\']([^"\']*)["\']#i', $segment, $m)) {
                foreach ($m[1] as $src) {
                    $links[] = self::link(html_entity_decode($src, ENT_QUOTES, 'UTF-8'), '');
                }
            }

            $text = self::clean_text($segment);
            if (($text === '' || $text === '•') && !$links) {
                continue;
            }
            $lines[] = array('text' => $text, 'links' => $links);
        }
        return $lines;
    }

    private static function link($href, $text) {
        $href = trim($href);
        $path = (string) wp_parse_url($href, PHP_URL_PATH);
        return array(
            'href'     => $href,
            'text'     => $text,
            'filename' => urldecode(wp_basename($path)),
            'is_file'  => self::is_churchedit_file($href),
        );
    }

    private static function clean_text($html) {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
        // \p{Z} also catches the non-breaking spaces and U+2028 line
        // separators ChurchEdit's editor leaves in text, which \s doesn't.
        return trim(preg_replace('/[\s\p{Z}\x{200B}]+/u', ' ', $text));
    }

    /**
     * How a line is compared between the two exports: its text plus the
     * files/pages it links to (a link swapped for a new document with the
     * same wording is still a change).
     */
    private static function line_key($line) {
        $key = self::norm($line['text']);
        foreach ($line['links'] as $link) {
            $key .= ' |' . self::norm_file($link['filename']);
        }
        return $key;
    }

    /**
     * Everything on the page a line could be matched against: the visible
     * text, plus text and file names stored in block attributes (e.g. a
     * downloads-table or key-contact block keeps its titles/URLs there).
     */
    private static function haystack($content) {
        $with_attrs = preg_replace_callback('/<!--\s*\/?wp:[^\s]+\s*(\{.*?\})?\s*\/?-->/s', function ($m) {
            return empty($m[1]) ? "\n" : "\n" . str_replace(array('\\/', '","', '":"'), array('/', "\n", ' '), json_encode(json_decode($m[1], true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";
        }, $content);

        $text  = '';
        $files = array();
        foreach (self::lines($with_attrs) as $line) {
            $text .= ' ' . self::norm($line['text']) . ' ';
            foreach ($line['links'] as $link) {
                $files[self::norm_file($link['filename'])] = true;
            }
        }
        // File URLs that sit in block attributes rather than an <a>/<img>.
        if (preg_match_all('#https?://[^\s"\'<>]+/([^/\s"\'<>?]+\.(?:' . implode('|', self::FILE_EXTENSIONS) . '))\\b#i', $with_attrs, $m)) {
            foreach ($m[1] as $filename) {
                $files[self::norm_file(urldecode($filename))] = true;
            }
        }
        return array('text' => $text, 'files' => $files);
    }

    private static function on_page($line, $haystack) {
        $text = self::norm($line['text']);
        if ($text !== '' && strpos($haystack['text'], $text) === false) {
            return false;
        }
        foreach ($line['links'] as $link) {
            if ($link['is_file'] && !isset($haystack['files'][self::norm_file($link['filename'])])) {
                return false;
            }
        }
        return $text !== '' || !empty($line['links']);
    }

    private static function norm($text) {
        $text = strtolower(str_replace(array('‘', '’', '“', '”', '–', '—'), array("'", "'", '"', '"', '-', '-'), $text));
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\'"&%£$@.,:;!?()\/+-]+/u', ' ', $text)));
    }

    /**
     * File names are compared the way WordPress stores them: sanitised on
     * upload, and given a -1/-2… suffix when the name was already taken.
     */
    private static function norm_file($filename) {
        $name = strtolower(sanitize_file_name($filename));
        return preg_replace('/-\d+(\.[a-z0-9]+)$/', '$1', $name);
    }

    /**
     * Longest common subsequence of two key lists, as [[i, j], ...] pairs.
     */
    private static function lcs_pairs($a, $b) {
        $n = count($a);
        $m = count($b);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = ($a[$i] === $b[$j]) ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $pairs = array();
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $pairs[] = array($i, $j);
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }
        return $pairs;
    }
}
