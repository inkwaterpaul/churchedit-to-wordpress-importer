<?php
/**
 * SQL Dump Parser Class
 * Extracts rows from specific tables in a mysqldump .sql file without needing a live MySQL connection.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_SQL_Dump_Parser {

    /**
     * Parse the given tables out of a mysqldump .sql file.
     *
     * @param string $file_path Absolute path to the .sql file
     * @param array  $tables    Table names to extract, e.g. ['folders', 'pages']
     * @return array|WP_Error   [ table_name => [ row_assoc_array, ... ] ] or WP_Error
     */
    public static function parse_tables($file_path, $tables) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return new WP_Error('csi_file_not_found', __('SQL file not found or not readable.', 'churchedit-sql-importer'));
        }

        // Raise the limit for the rest of this request rather than restoring it
        // afterwards — by the time parsing finishes, usage is already above most
        // default limits, so restoring a lower value only produces a warning.
        if (ini_get('memory_limit') !== '-1') {
            ini_set('memory_limit', '1024M');
        }
        @set_time_limit(300);

        $contents = file_get_contents($file_path);
        if ($contents === false) {
            return new WP_Error('csi_read_error', __('Could not read SQL file.', 'churchedit-sql-importer'));
        }

        $result = array();

        foreach ($tables as $table) {
            $columns = self::extract_columns($contents, $table);
            if (empty($columns)) {
                $result[$table] = array();
                continue;
            }

            $rows = array();
            $offset = 0;
            $needle = "INSERT INTO `{$table}` (";

            while (($pos = strpos($contents, $needle, $offset)) !== false) {
                $values_pos = strpos($contents, "VALUES\n", $pos);
                if ($values_pos === false) {
                    break;
                }
                $values_start = $values_pos + strlen("VALUES\n");
                $stmt_end = strpos($contents, ";\n", $values_start);
                if ($stmt_end === false) {
                    break;
                }

                $tuples_text = substr($contents, $values_start, $stmt_end - $values_start);
                foreach (self::split_tuples($tuples_text) as $tuple_text) {
                    $fields = self::split_fields($tuple_text);
                    $fields = array_map(array(__CLASS__, 'clean_value'), $fields);
                    if (count($fields) === count($columns)) {
                        $rows[] = array_combine($columns, $fields);
                    }
                }

                $offset = $stmt_end + 2;
            }

            $result[$table] = $rows;
        }

        return $result;
    }

    /**
     * Pull the ordered column list from a table's CREATE TABLE statement.
     */
    private static function extract_columns($contents, $table) {
        $needle = "CREATE TABLE `{$table}` (";
        $start = strpos($contents, $needle);
        if ($start === false) {
            return array();
        }
        $start += strlen($needle);
        $end = strpos($contents, "\n) ENGINE", $start);
        if ($end === false) {
            return array();
        }
        $body = substr($contents, $start, $end - $start);

        $columns = array();
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '`') {
                $close = strpos($line, '`', 1);
                if ($close !== false) {
                    $columns[] = substr($line, 1, $close - 1);
                }
            }
        }
        return $columns;
    }

    /**
     * Split a VALUES block into top-level tuples, respecting quoted strings
     * with backslash escaping (mysqldump style).
     */
    private static function split_tuples($text) {
        $tuples = array();
        $len = strlen($text);
        $depth = 0;
        $in_str = false;
        $current = '';
        $i = 0;

        while ($i < $len) {
            $c = $text[$i];

            if ($in_str) {
                $current .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $current .= $text[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === "'") {
                    $in_str = false;
                }
                $i++;
                continue;
            }

            if ($c === "'") {
                $in_str = true;
                $current .= $c;
                $i++;
                continue;
            }

            if ($c === '(') {
                if ($depth === 0) {
                    $current = '';
                } else {
                    $current .= $c;
                }
                $depth++;
                $i++;
                continue;
            }

            if ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $tuples[] = $current;
                } else {
                    $current .= $c;
                }
                $i++;
                continue;
            }

            if ($depth > 0) {
                $current .= $c;
            }
            $i++;
        }

        return $tuples;
    }

    /**
     * Split one tuple's inner text into fields on top-level commas.
     */
    private static function split_fields($text) {
        $fields = array();
        $len = strlen($text);
        $in_str = false;
        $depth = 0;
        $current = '';
        $i = 0;

        while ($i < $len) {
            $c = $text[$i];

            if ($in_str) {
                $current .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $current .= $text[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === "'") {
                    $in_str = false;
                }
                $i++;
                continue;
            }

            if ($c === "'") {
                $in_str = true;
                $current .= $c;
                $i++;
                continue;
            }

            if ($c === '(' || $c === '[') {
                $depth++;
                $current .= $c;
                $i++;
                continue;
            }

            if ($c === ')' || $c === ']') {
                $depth--;
                $current .= $c;
                $i++;
                continue;
            }

            if ($c === ',' && $depth === 0) {
                $fields[] = $current;
                $current = '';
                $i++;
                continue;
            }

            $current .= $c;
            $i++;
        }
        $fields[] = $current;

        return $fields;
    }

    /**
     * Turn a raw SQL literal into a PHP value: NULL -> null, 'quoted' -> unescaped string.
     */
    private static function clean_value($value) {
        $value = trim($value);
        if ($value === 'NULL') {
            return null;
        }
        if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            $inner = substr($value, 1, -1);
            $inner = str_replace(
                array("\\'", '\\"', '\\r', '\\n', '\\\\'),
                array("'", '"', "\r", "\n", '\\'),
                $inner
            );
            return $inner;
        }
        return $value;
    }
}
