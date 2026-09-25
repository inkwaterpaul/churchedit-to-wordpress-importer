<?php
/**
 * Content Merger Class
 * Three-way merge used by Compare & Update so a targeted update only touches
 * what actually changed in ChurchEdit, instead of overwriting the whole post
 * (and with it any edits made in WordPress since the original import).
 *
 *   old     = what the previous export converts to (what was imported)
 *   new     = what the current export converts to
 *   current = what's in WordPress now
 *
 * The exact old→new changes are worked out at block level (recursing into
 * container blocks such as accordions, groups and the block pattern
 * wrapper), then each one is located in the current content and applied
 * there. If a change can't be located — because the same part was also
 * edited in WordPress — it's reported as a conflict and nothing is written.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Content_Merger {

    /**
     * Merge a set of simple post fields (post_title, post_content,
     * post_excerpt, or any scalar) old → new onto an existing post.
     *
     * post_content gets the block-level merge; every other field is a plain
     * three-way compare: only written if the source changed it, and only
     * if WordPress still has the old value (otherwise it's a conflict).
     *
     * @param int   $post_id    Existing post
     * @param array $old_fields Field => value as the old export produces it
     * @param array $new_fields Field => value as the new export produces it
     * @return array ['fields' => changed field => value, 'conflicts' => string[]]
     */
    public static function merge_post_fields($post_id, $old_fields, $new_fields) {
        $post = get_post($post_id);
        $fields    = array();
        $conflicts = array();

        foreach ($new_fields as $key => $new_value) {
            $old_value = isset($old_fields[$key]) ? $old_fields[$key] : '';
            $current   = isset($post->$key) ? $post->$key : '';

            if ($key === 'post_content') {
                $merge = self::merge_content($old_value, $new_value, $current);
                if (!empty($merge['conflicts'])) {
                    $conflicts = array_merge($conflicts, $merge['conflicts']);
                } elseif ($merge['content'] !== $current) {
                    $fields[$key] = $merge['content'];
                }
                continue;
            }

            if ((string) $old_value === (string) $new_value || (string) $current === (string) $new_value) {
                continue;
            }
            if (self::normalise((string) $current) === self::normalise((string) $old_value)) {
                $fields[$key] = $new_value;
            } else {
                $conflicts[] = sprintf('%s was changed in ChurchEdit and also edited in WordPress', str_replace('post_', '', $key));
            }
        }

        return array('fields' => $fields, 'conflicts' => $conflicts);
    }

    /**
     * Shared by every importer's merge path: run merge_post_fields() and
     * either return the fields ready for wp_update_post() (slashed — they're
     * built from content already stored in the database, which
     * wp_update_post() would otherwise unslash a second time), or a failed
     * result row explaining why the post was left alone.
     *
     * @return array ['fields' => array|null, 'result' => array|null]
     */
    public static function prepare_update($post_id, $old_fields, $new_fields, $ref, $title) {
        if ($old_fields === null) {
            return array('fields' => null, 'result' => array(
                'ref' => $ref, 'title' => $title, 'success' => false,
                'error' => 'Not updated — no old version to compare against. Tick "Replace whole content" to overwrite it.',
            ));
        }

        $merge = self::merge_post_fields($post_id, $old_fields, $new_fields);
        if (!empty($merge['conflicts'])) {
            return array('fields' => null, 'result' => array(
                'ref' => $ref, 'title' => $title, 'success' => false,
                'error' => 'Not updated — ' . implode('; ', $merge['conflicts']) . '. Update it by hand, or tick "Replace whole content" to overwrite it.',
            ));
        }
        if (empty($merge['fields'])) {
            return array('fields' => null, 'result' => array(
                'ref' => $ref, 'post_id' => $post_id, 'title' => $title, 'success' => true, 'action' => 'unchanged',
            ));
        }

        return array('fields' => wp_slash($merge['fields']), 'result' => null);
    }

    /**
     * @return array ['content' => merged string, 'conflicts' => string[]]
     */
    public static function merge_content($old, $new, $current) {
        if ($old === $new) {
            return array('content' => $current, 'conflicts' => array());
        }

        $conflicts = array();
        $tokens = self::merge_tokens(
            self::top_level_tokens(parse_blocks($old)),
            self::top_level_tokens(parse_blocks($new)),
            self::top_level_tokens(parse_blocks($current)),
            $conflicts
        );

        if (!empty($conflicts)) {
            return array('content' => $current, 'conflicts' => $conflicts);
        }
        return array('content' => self::serialize_tokens($tokens), 'conflicts' => array());
    }

    /**
     * A block list as a flat sequence of tokens: each token is either a
     * parsed block (array) or a raw HTML string. At the top level the
     * strings are parse_blocks()' freeform (null-name) blocks; inside a
     * container they're the pieces of its innerContent between inner blocks
     * (the container's own wrapper markup, whitespace, or loose HTML such as
     * a page-links placeholder comment).
     */
    private static function top_level_tokens($blocks) {
        $tokens = array();
        foreach ($blocks as $block) {
            $tokens[] = ($block['blockName'] === null) ? $block['innerHTML'] : $block;
        }
        return $tokens;
    }

    private static function inner_tokens($block) {
        $tokens = array();
        $i = 0;
        foreach ($block['innerContent'] as $chunk) {
            if ($chunk === null) {
                $tokens[] = $block['innerBlocks'][$i++];
            } else {
                $tokens[] = $chunk;
            }
        }
        return $tokens;
    }

    private static function rebuild_block($block, $tokens) {
        $block['innerBlocks']  = array();
        $block['innerContent'] = array();
        $html = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $block['innerBlocks'][]  = $token;
                $block['innerContent'][] = null;
            } else {
                $html .= $token;
                $last = count($block['innerContent']) - 1;
                if ($last >= 0 && is_string($block['innerContent'][$last])) {
                    $block['innerContent'][$last] .= $token;
                } else {
                    $block['innerContent'][] = $token;
                }
            }
        }
        $block['innerHTML'] = $html;
        return $block;
    }

    private static function serialize_tokens($tokens) {
        $out = '';
        foreach ($tokens as $token) {
            $out .= is_array($token) ? serialize_block($token) : $token;
        }
        return $out;
    }

    private static function token_string($token) {
        return is_array($token) ? serialize_block($token) : $token;
    }

    /**
     * Whitespace between blocks is positional padding, not content — it
     * never takes part in matching.
     */
    private static function is_significant($token) {
        return is_array($token) || trim(str_replace("\xC2\xA0", ' ', $token)) !== '';
    }

    /**
     * Loose fingerprint used to recognise an old block in the current
     * WordPress content. It has to survive what later import steps do to
     * the content: Link Media swaps a document/image href/src for the
     * uploaded attachment's URL (same filename), and WordPress strips
     * stray backslashes on save. So URLs are reduced to their filename and
     * block comment attributes (e.g. an image's attachment id) are ignored.
     *
     * It also has to survive a page simply being opened and saved in the
     * block editor, which re-serialises every block's markup — adding
     * classes (<hr> → <hr class="wp-block-separator …"/>), reordering
     * attributes, turning spaces into &nbsp; and changing whitespace
     * between tags — without anyone changing the content. So every
     * attribute other than href/src is dropped, and whitespace between
     * tags is ignored.
     */
    private static function fingerprint($token) {
        $name = is_array($token) ? (string) $token['blockName'] : '#html';
        return $name . '|' . self::normalise(self::token_string($token));
    }

    private static function normalise($html) {
        $html = preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', $html);
        $html = preg_replace_callback('/\b(href|src)="([^"]*)"/i', function ($m) {
            $path = preg_replace('/[?#].*$/', '', $m[2]);
            return $m[1] . '="' . strtolower(sanitize_file_name(urldecode(wp_basename($path)))) . '"';
        }, $html);
        $html = preg_replace_callback('/<([a-z][a-z0-9-]*)\b([^>]*)>/i', function ($m) {
            preg_match_all('/\b(?:href|src)="[^"]*"/i', $m[2], $keep);
            return '<' . strtolower($m[1]) . (empty($keep[0]) ? '' : ' ' . implode(' ', $keep[0])) . '>';
        }, $html);
        $html = str_replace('\\', '', $html);
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $html = str_replace("\xC2\xA0", ' ', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        return trim(preg_replace('/\s+/u', ' ', $html));
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

    /**
     * Three-way merge of token lists. Works on the significant tokens only
     * (index maps back to raw positions) so whitespace padding in any of
     * the three never affects alignment.
     */
    private static function merge_tokens($o_raw, $n_raw, $w_raw, &$conflicts) {
        $o_idx = array_keys(array_filter($o_raw, array(__CLASS__, 'is_significant')));
        $n_idx = array_keys(array_filter($n_raw, array(__CLASS__, 'is_significant')));
        $w_idx = array_keys(array_filter($w_raw, array(__CLASS__, 'is_significant')));

        $o_exact = array();
        $o_fp    = array();
        foreach ($o_idx as $r) {
            $o_exact[] = self::token_string($o_raw[$r]);
            $o_fp[]    = self::fingerprint($o_raw[$r]);
        }
        $n_exact = array();
        foreach ($n_idx as $r) {
            $n_exact[] = self::token_string($n_raw[$r]);
        }
        $w_fp = array();
        foreach ($w_idx as $r) {
            $w_fp[] = self::fingerprint($w_raw[$r]);
        }

        // old → new: exactly what changed in the source.
        $hunks = array();
        $prev_o = 0;
        $prev_n = 0;
        $on_pairs = self::lcs_pairs($o_exact, $n_exact);
        $on_pairs[] = array(count($o_exact), count($n_exact)); // sentinel
        foreach ($on_pairs as $pair) {
            if ($pair[0] > $prev_o || $pair[1] > $prev_n) {
                $hunks[] = array($prev_o, $pair[0], $prev_n, $pair[1]);
            }
            $prev_o = $pair[0] + 1;
            $prev_n = $pair[1] + 1;
        }
        if (empty($hunks)) {
            return $w_raw;
        }

        // old → current: where each old token lives in WordPress now.
        $o_to_w = array();
        foreach (self::lcs_pairs($o_fp, $w_fp) as $pair) {
            $o_to_w[$pair[0]] = $pair[1];
        }

        // Each edit: [raw start, raw length, replacement tokens].
        $edits = array();
        foreach ($hunks as $hunk) {
            list($oa, $ob, $na, $nb) = $hunk;

            // One container block changed into the same kind of container:
            // descend into it so only the changed part inside is touched.
            if ($ob - $oa === 1 && $nb - $na === 1) {
                $o_tok = $o_raw[$o_idx[$oa]];
                $n_tok = $n_raw[$n_idx[$na]];
                if (self::is_same_container($o_tok, $n_tok)) {
                    $w = self::locate_container($oa, $o_raw, $o_idx, $o_to_w, $w_raw, $w_idx);
                    if ($w === null) {
                        if (!self::already_applied(array($n_tok), $w_fp)) {
                            $conflicts[] = self::describe($o_tok, 'section was edited or moved in WordPress');
                        }
                        continue;
                    }
                    $w_tok = $w_raw[$w_idx[$w]];
                    $inner = self::merge_tokens(self::inner_tokens($o_tok), self::inner_tokens($n_tok), self::inner_tokens($w_tok), $conflicts);
                    $edits[] = array($w_idx[$w], 1, array(self::rebuild_block($w_tok, $inner)));
                    continue;
                }
            }

            $replacement = ($nb > $na) ? array_slice($n_raw, $n_idx[$na], $n_idx[$nb - 1] - $n_idx[$na] + 1) : array();

            if ($ob > $oa) {
                // Every old token being replaced/removed must still be in
                // WordPress, unedited and in one contiguous run.
                $ws = array();
                for ($o = $oa; $o < $ob; $o++) {
                    $ws[] = isset($o_to_w[$o]) ? $o_to_w[$o] : null;
                }
                if (in_array(null, $ws, true) || end($ws) - $ws[0] !== count($ws) - 1) {
                    if ($nb > $na && self::already_applied(array_filter($replacement, array(__CLASS__, 'is_significant')), $w_fp)) {
                        continue;
                    }
                    $missing = array_search(null, $ws, true);
                    $conflicts[] = self::describe($o_raw[$o_idx[$oa + ($missing === false ? 0 : $missing)]], 'was edited in WordPress');
                    continue;
                }
                $start = $w_idx[$ws[0]];
                $edits[] = array($start, $w_idx[end($ws)] - $start + 1, $replacement);
                continue;
            }

            // Pure insertion: anchor on the old neighbour just before it
            // (or just after it), wherever that now sits in WordPress.
            if ($oa > 0 && isset($o_to_w[$oa - 1])) {
                $at = $w_idx[$o_to_w[$oa - 1]] + 1;
            } elseif ($oa < count($o_idx) && isset($o_to_w[$oa])) {
                $at = $w_idx[$o_to_w[$oa]];
            } elseif (empty($o_idx) && empty($w_idx)) {
                $at = count($w_raw);
            } else {
                if (!self::already_applied(array_filter($replacement, array(__CLASS__, 'is_significant')), $w_fp)) {
                    $conflicts[] = self::describe($n_raw[$n_idx[$na]], 'is new, but the content around it was edited in WordPress');
                }
                continue;
            }
            $edits[] = array($at, 0, array_merge(array("\n\n"), $replacement));
        }

        if (!empty($conflicts)) {
            return $w_raw;
        }

        usort($edits, function ($a, $b) {
            return $b[0] - $a[0];
        });
        foreach ($edits as $edit) {
            array_splice($w_raw, $edit[0], $edit[1], $edit[2]);
        }
        return $w_raw;
    }

    /**
     * Whether a change from ChurchEdit has already been made by hand in
     * WordPress — the new tokens are already there, in a row — in which case
     * there's nothing to apply and it isn't a conflict.
     */
    private static function already_applied($new_tokens, $w_fp) {
        $needle = array();
        foreach ($new_tokens as $token) {
            $needle[] = self::fingerprint($token);
        }
        $len = count($needle);
        if ($len === 0) {
            return false;
        }
        for ($i = 0; $i + $len <= count($w_fp); $i++) {
            if (array_slice($w_fp, $i, $len) === $needle) {
                return true;
            }
        }
        return false;
    }

    private static function is_same_container($o_tok, $n_tok) {
        return is_array($o_tok) && is_array($n_tok)
            && $o_tok['blockName'] === $n_tok['blockName']
            && $o_tok['attrs'] == $n_tok['attrs']
            && (!empty($o_tok['innerBlocks']) || !empty($n_tok['innerBlocks']));
    }

    /**
     * Find the current-content counterpart of an old container block. An
     * exact fingerprint match only works if nothing inside it was edited in
     * WordPress; otherwise look between the nearest matched neighbours and
     * pair up same-type blocks by position (e.g. the 3rd unmatched accordion
     * item in the old content ↔ the 3rd in WordPress), provided both sides
     * have the same number of them there. The recursive merge then decides
     * what inside it is safe to change.
     */
    private static function locate_container($oa, $o_raw, $o_idx, $o_to_w, $w_raw, $w_idx) {
        if (isset($o_to_w[$oa])) {
            return $o_to_w[$oa];
        }
        $name = $o_raw[$o_idx[$oa]]['blockName'];

        $o_lo = -1;
        for ($o = $oa - 1; $o >= 0; $o--) {
            if (isset($o_to_w[$o])) {
                $o_lo = $o;
                break;
            }
        }
        $o_hi = count($o_idx);
        for ($o = $oa + 1; $o < count($o_idx); $o++) {
            if (isset($o_to_w[$o])) {
                $o_hi = $o;
                break;
            }
        }
        $w_lo = ($o_lo >= 0) ? $o_to_w[$o_lo] : -1;
        $w_hi = ($o_hi < count($o_idx)) ? $o_to_w[$o_hi] : count($w_idx);

        $o_same = array();
        for ($o = $o_lo + 1; $o < $o_hi; $o++) {
            $tok = $o_raw[$o_idx[$o]];
            if (is_array($tok) && $tok['blockName'] === $name) {
                $o_same[] = $o;
            }
        }
        $w_same = array();
        for ($w = $w_lo + 1; $w < $w_hi; $w++) {
            $tok = $w_raw[$w_idx[$w]];
            if (is_array($tok) && $tok['blockName'] === $name) {
                $w_same[] = $w;
            }
        }

        if (count($o_same) !== count($w_same)) {
            return null;
        }
        return $w_same[array_search($oa, $o_same, true)];
    }

    private static function describe($token, $reason) {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(self::token_string($token))));
        if ($text === '') {
            $text = is_array($token) && $token['blockName'] ? $token['blockName'] . ' block' : 'HTML';
        }
        return '"' . wp_trim_words($text, 8, '…') . '" ' . $reason;
    }
}
