<?php
/**
 * Member Importer Class
 *
 * Imports ChurchEdit's `members` table as WordPress users. Passwords are never
 * migrated (ChurchEdit's hashes are a mix of formats, some possibly bcrypt but
 * not verified-compatible with this WP install's hashing, others plainly legacy
 * MD5/SHA1-style) — every imported account gets a random password and is
 * expected to go through WordPress's normal password-reset flow. No notification
 * emails are sent by this importer.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Member_Importer {

    const ROLE_ADMIN_TIER = 'editor';
    const ROLE_DEFAULT     = 'contributor';

    /**
     * Filter the raw `members` rows down to the ones actually worth importing:
     * not soft-deleted in the source, and has the minimum identity fields.
     */
    public static function filter_live($members) {
        return array_values(array_filter($members, function ($m) {
            if (!empty($m['deleted']) && $m['deleted'] === 'Y') {
                return false;
            }
            return !empty($m['email']) && !empty($m['username']);
        }));
    }

    /**
     * @param array $member One row from the `members` table
     * @return array Result: ['member_id'=>, 'user_id'=>, 'action'=>'created'|'updated'|'skipped', 'success'=>bool, 'error'=>string|null]
     */
    public static function import_member($member) {
        try {
            $member_id = $member['member_id'];
            $email     = sanitize_email($member['email']);

            if (empty($email) || !is_email($email)) {
                return array('member_id' => $member_id, 'success' => false, 'error' => 'Invalid or missing email');
            }

            $role = ($member['master_access'] === 'Y') ? self::ROLE_ADMIN_TIER : self::ROLE_DEFAULT;

            $existing_user_id = email_exists($email);

            if ($existing_user_id) {
                // Account already exists on this WP install (e.g. an existing admin) —
                // don't touch its password or clobber its role, just record the link.
                update_user_meta($existing_user_id, '_ce_member_id', $member_id);
                return array(
                    'member_id' => $member_id,
                    'user_id'   => $existing_user_id,
                    'action'    => 'skipped',
                    'success'   => true,
                    'note'      => 'Email already exists on this site — linked via meta only, role/password untouched.',
                );
            }

            $login = self::unique_login(sanitize_user($member['username'], true));

            $user_id = wp_insert_user(array(
                'user_login' => $login,
                'user_email' => $email,
                'user_pass'  => wp_generate_password(24, true, true),
                'first_name' => $member['firstname'] ? $member['firstname'] : '',
                'last_name'  => $member['surname'] ? $member['surname'] : '',
                'display_name' => trim($member['firstname'] . ' ' . $member['surname']) ?: $login,
                'role'       => $role,
            ));

            if (is_wp_error($user_id)) {
                return array('member_id' => $member_id, 'success' => false, 'error' => $user_id->get_error_message());
            }

            update_user_meta($user_id, '_ce_member_id', $member_id);
            update_user_meta($user_id, '_ce_source_master_access', $member['master_access']);

            return array(
                'member_id' => $member_id,
                'user_id'   => $user_id,
                'login'     => $login,
                'role'      => $role,
                'action'    => 'created',
                'success'   => true,
            );
        } catch (Exception $e) {
            return array('member_id' => $member['member_id'], 'success' => false, 'error' => 'Exception: ' . $e->getMessage());
        } catch (Error $e) {
            return array('member_id' => $member['member_id'], 'success' => false, 'error' => 'Fatal error: ' . $e->getMessage());
        }
    }

    /**
     * Append a numeric suffix if the sanitized username is already taken by an
     * unrelated account, so import never silently fails on a collision.
     */
    private static function unique_login($login) {
        if (empty($login)) {
            $login = 'member';
        }
        $base = $login;
        $i = 1;
        while (username_exists($login)) {
            $i++;
            $login = $base . $i;
        }
        return $login;
    }
}
