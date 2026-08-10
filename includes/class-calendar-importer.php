<?php
/**
 * Calendar Importer Class
 *
 * Imports ChurchEdit's `calendar` table (a separate table from `pages`,
 * entirely independent of the folder hierarchy) into The Events Calendar's
 * `tribe_events` post type, using its official tribe_create_event()/
 * tribe_create_venue() API rather than hand-rolling meta keys.
 *
 * Note: `category_id` on each event has no matching category-name table in
 * this SQL export, so it's stored as postmeta only — no taxonomy term is
 * assigned. Map it manually afterward if the category names matter.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Calendar_Importer {

    /**
     * @param array $event     One row from the `calendar` table
     * @param array $locations Map of location_id => row from `calendar_locations`
     * @param array $options   ['default_status' => 'draft'|'publish'|'pending']
     * @return array Result: ['ref'=>, 'post_id'=>, 'title'=>, 'action'=>, 'success'=>, 'error'=>null]
     */
    public static function import_event($event, $locations, $options = array()) {
        try {
            if (!class_exists('Tribe__Events__Main') || !function_exists('tribe_create_event')) {
                return array('ref' => 'event:' . $event['event_id'], 'success' => false, 'error' => 'The Events Calendar plugin is not active.');
            }

            $event_id = $event['event_id'];
            $ref = 'event:' . $event_id;

            $start = self::build_datetime($event['year'], $event['month'], $event['day'], $event['hour'], $event['minute']);
            if (!$start) {
                return array('ref' => $ref, 'success' => false, 'error' => 'Could not build a valid start date from source fields.');
            }
            $end = self::build_datetime($event['year'], $event['month'], $event['day'], $event['FinishHour'], $event['FinishMinute']);
            if (!$end || $end < $start) {
                $end = $start;
            }

            $all_day = ($event['AllDay'] === 'Y') || ($event['hour'] === null || $event['hour'] === '');

            $is_private = ($event['private'] === 'Y') || (!empty($event['event_access']) && $event['event_access'] !== 'everyone');
            $status = $is_private ? 'draft' : $options['default_status'];

            $content = CSI_Content_Converter::convert($event['long_event']);

            $args = array(
                'post_title'    => wp_strip_all_tags($event['short_event']),
                'post_content'  => $content,
                'post_excerpt'  => $event['summary'] ? wp_strip_all_tags($event['summary']) : '',
                'post_status'   => $status,
                'EventStartDate' => $start->format('Y-m-d'),
                'EventStartHour' => $start->format('H'),
                'EventStartMinute' => $start->format('i'),
                'EventEndDate'  => $end->format('Y-m-d'),
                'EventEndHour'  => $end->format('H'),
                'EventEndMinute' => $end->format('i'),
                'EventAllDay'   => $all_day,
            );

            if (!empty($event['web_address'])) {
                $args['EventURL'] = $event['web_address'];
            }

            $location_id = self::norm_id($event['location_id']);
            if ($location_id !== null && isset($locations[$location_id])) {
                $venue_post_id = self::get_or_create_venue($locations[$location_id]);
                if ($venue_post_id) {
                    $args['Venue'] = array('VenueID' => $venue_post_id);
                }
            }

            $existing = self::find_existing_event($ref);

            // See CSI_Post_Importer::import_post() for why this is dropped
            // rather than set on a targeted-update pass — tribe_update_event()
            // flows straight through to wp_update_post(), which merges
            // omitted keys from the existing post.
            if ($existing && !empty($options['preserve_on_update'])) {
                unset($args['post_status']);
                // Report the status the event actually keeps, not the one
                // that would have been applied had it not been preserved.
                $status = get_post($existing)->post_status;
            }

            if ($existing) {
                $args['ID'] = $existing;
                $post_id = tribe_update_event($existing, $args);
                $action = 'updated';
            } else {
                $args['post_author'] = get_current_user_id();
                $post_id = tribe_create_event($args);
                $action = 'created';
            }

            if (!$post_id) {
                return array('ref' => $ref, 'success' => false, 'error' => 'tribe_create_event/tribe_update_event failed.');
            }

            update_post_meta($post_id, '_ce_source_ref', $ref);
            update_post_meta($post_id, '_ce_event_id', $event_id);
            if (!empty($event['category_id'])) {
                update_post_meta($post_id, '_ce_category_id', $event['category_id']);
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
                'ref'     => $ref,
                'post_id' => $post_id,
                'title'   => $event['short_event'],
                'action'  => $action,
                'success' => true,
                'draft'   => ($status === 'draft'),
            );
        } catch (Exception $e) {
            return array('ref' => 'event:' . $event['event_id'], 'success' => false, 'error' => 'Exception: ' . $e->getMessage());
        } catch (Error $e) {
            return array('ref' => 'event:' . $event['event_id'], 'success' => false, 'error' => 'Fatal error: ' . $e->getMessage());
        }
    }

    /**
     * ChurchEdit's `hour`/`FinishHour` fields are 24-hour values (confirmed
     * against samples where hour=13 pairs with FinishHour=14) — the am_pm
     * column is inconsistent with that and is not used here.
     */
    private static function build_datetime($year, $month, $day, $hour, $minute) {
        if (empty($year) || empty($month) || empty($day)) {
            return false;
        }
        $hour   = ($hour === null || $hour === '') ? 0 : (int) $hour;
        $minute = ($minute === null || $minute === '') ? 0 : (int) $minute;

        $date = sprintf('%04d-%02d-%02d %02d:%02d:00', (int) $year, (int) $month, (int) $day, $hour, $minute);
        try {
            return new DateTime($date);
        } catch (Exception $e) {
            return false;
        }
    }

    private static function get_or_create_venue($location) {
        $location_id = $location['id'];

        $existing = get_posts(array(
            'post_type'      => 'tribe_venue',
            'post_status'    => array('publish', 'draft'),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_ce_location_id', 'value' => $location_id),
            ),
        ));
        if (!empty($existing)) {
            return (int) $existing[0];
        }

        $address = trim(implode(', ', array_filter(array($location['address_1'], $location['address_2'], $location['address_3']))));

        $venue_id = tribe_create_venue(array(
            'Venue'    => $location['name'] ? $location['name'] : __('Unnamed venue', 'churchedit-sql-importer'),
            'Address'  => $address,
            'City'     => $location['town'],
            'Province' => $location['county'],
            'Zip'      => $location['postcode'],
            'Country'  => $location['country'],
        ));

        if ($venue_id) {
            update_post_meta($venue_id, '_ce_location_id', $location_id);
        }

        return $venue_id ? (int) $venue_id : 0;
    }

    private static function find_existing_event($ref) {
        $existing = get_posts(array(
            'post_type'      => 'tribe_events',
            'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_ce_source_ref', 'value' => $ref),
            ),
        ));
        return !empty($existing) ? (int) $existing[0] : 0;
    }

    private static function norm_id($value) {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        return (string) $value;
    }

    /**
     * Keep only events on or after $from_date (Y-m-d). Events with an
     * unparseable date are kept rather than silently dropped — import_event()
     * will surface the error instead.
     */
    public static function filter_from_date($events, $from_date) {
        try {
            $cutoff = new DateTime($from_date);
        } catch (Exception $e) {
            return $events;
        }

        return array_values(array_filter($events, function ($event) use ($cutoff) {
            if (empty($event['year']) || empty($event['month']) || empty($event['day'])) {
                return true;
            }
            $event_date = DateTime::createFromFormat('Y-n-j', $event['year'] . '-' . $event['month'] . '-' . $event['day']);
            if (!$event_date) {
                return true;
            }
            return $event_date >= $cutoff;
        }));
    }
}
