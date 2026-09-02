<?php
/**
 * Admin UI Class
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class CSI_Admin_UI {

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'churchedit-sql-importer'));
        }
        ?>
        <div class="wrap csi-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="csi-container">
                <div class="csi-card">

                    <h2><?php esc_html_e('1. Parse SQL Export', 'churchedit-sql-importer'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('ZIP the ChurchEdit .sql export and upload it here (same as the page/post HTML importers\' folder upload). It\'s extracted on the server and re-used on repeat uploads of the same ZIP.', 'churchedit-sql-importer'); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csi-sql-zip"><?php esc_html_e('SQL export (ZIP)', 'churchedit-sql-importer'); ?></label></th>
                            <td>
                                <input type="file" id="csi-sql-zip" accept=".zip">
                                <button type="button" class="button button-primary" id="csi-upload-parse-btn"><?php esc_html_e('Upload & Parse', 'churchedit-sql-importer'); ?></button>
                                <input type="hidden" id="csi-sql-path" value="">
                            </td>
                        </tr>
                    </table>
                    <div id="csi-parse-status"></div>

                    <div id="csi-summary" style="display:none;"></div>

                    <div id="csi-import-options" style="display:none;">
                        <h2><?php esc_html_e('2. Import Options', 'churchedit-sql-importer'); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="csi-default-status"><?php esc_html_e('Default status', 'churchedit-sql-importer'); ?></label></th>
                                <td>
                                    <select id="csi-default-status">
                                        <option value="draft"><?php esc_html_e('Draft', 'churchedit-sql-importer'); ?></option>
                                        <option value="pending"><?php esc_html_e('Pending Review', 'churchedit-sql-importer'); ?></option>
                                        <option value="publish"><?php esc_html_e('Published', 'churchedit-sql-importer'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Pages flagged as restricted/hidden/-noshow in the source are always imported as drafts regardless of this setting.', 'churchedit-sql-importer'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="csi-block-pattern"><?php esc_html_e('Block layout pattern (optional)', 'churchedit-sql-importer'); ?></label></th>
                                <td>
                                    <textarea id="csi-block-pattern" rows="8" class="large-text code"><!-- wp:columns {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}}} -->
<div class="wp-block-columns alignwide" style="margin-top:var(--wp--preset--spacing--50);margin-bottom:var(--wp--preset--spacing--50)"><!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"><!-- wp:octopus/page-navigation /--></div>
<!-- /wp:column -->

<!-- wp:column {"width":"75%"} -->
<div class="wp-block-column" style="flex-basis:75%">{content}</div>
<!-- /wp:column --></div>
<!-- /wp:columns --></textarea>
                                    <p class="description"><?php esc_html_e('Pages only — every imported page\'s converted content is wrapped in this block pattern, replacing {content}. Posts, Vacancies, and Calendar events never use it, since the sidebar navigation it\'s built around is a Pages-hierarchy concept. Clear the box to import raw converted blocks with no wrapper.', 'churchedit-sql-importer'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div id="csi-tree-wrap" style="display:none;">
                        <h2><?php esc_html_e('3. Select what to import', 'churchedit-sql-importer'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Feature/module folders are greyed out and excluded by default. Large folders (likely blog archives) are unchecked by default. Pages flagged D (draft-forced) or C (card-grid candidate) are annotated.', 'churchedit-sql-importer'); ?>
                        </p>
                        <div id="csi-tree"></div>
                        <p>
                            <button type="button" class="button button-primary button-large" id="csi-import-btn"><?php esc_html_e('Import Selected', 'churchedit-sql-importer'); ?></button>
                        </p>
                    </div>

                    <div id="csi-progress" class="csi-progress" style="display:none;">
                        <div class="csi-progress-bar"><div class="csi-progress-bar-fill" id="csi-progress-bar-fill"></div></div>
                        <p class="csi-progress-text" id="csi-progress-text">0%</p>
                    </div>

                    <div id="csi-results" style="display:none;">
                        <h3><?php esc_html_e('Import Results', 'churchedit-sql-importer'); ?></h3>
                        <div id="csi-results-content"></div>
                    </div>

                    <hr>

                    <h2><?php esc_html_e('4. Import Folder as Posts', 'churchedit-sql-importer'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('For flat/blog-style folders (like "news", or a parish\'s own blog) — imports every page in the chosen folder as a flat WP Post, no hierarchy. Any folder can be picked here, not just the large ones excluded from the Pages tree above — if you use this for a folder that\'s also checked in the Pages tree, uncheck it there first so it isn\'t imported twice. Uses the SQL file parsed in step 1.', 'churchedit-sql-importer'); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csi-posts-folder"><?php esc_html_e('Folder', 'churchedit-sql-importer'); ?></label></th>
                            <td><select id="csi-posts-folder"><option value=""><?php esc_html_e('Parse the SQL file first', 'churchedit-sql-importer'); ?></option></select></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="csi-posts-default-status"><?php esc_html_e('Default status', 'churchedit-sql-importer'); ?></label></th>
                            <td>
                                <select id="csi-posts-default-status">
                                    <option value="draft"><?php esc_html_e('Draft', 'churchedit-sql-importer'); ?></option>
                                    <option value="pending"><?php esc_html_e('Pending Review', 'churchedit-sql-importer'); ?></option>
                                    <option value="publish"><?php esc_html_e('Published', 'churchedit-sql-importer'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p><button type="button" class="button button-primary" id="csi-import-posts-btn"><?php esc_html_e('Import Folder as Posts', 'churchedit-sql-importer'); ?></button></p>
                    <div id="csi-posts-progress" class="csi-progress" style="display:none;">
                        <div class="csi-progress-bar"><div class="csi-progress-bar-fill" id="csi-posts-progress-bar-fill"></div></div>
                        <p class="csi-progress-text" id="csi-posts-progress-text">0%</p>
                    </div>
                    <div id="csi-posts-results-content"></div>

                    <hr>

                    <h2><?php esc_html_e('5. Import Calendar', 'churchedit-sql-importer'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('The calendar table is separate from pages/folders entirely. Imports events into The Events Calendar (tribe_events), creating venues from calendar_locations as needed. Event categories aren\'t mapped (no category-name table in this export) — the source category_id is stored as postmeta for manual mapping.', 'churchedit-sql-importer'); ?>
                    </p>
                    <p>
                        <button type="button" class="button" id="csi-parse-calendar-btn"><?php esc_html_e('Scan Calendar', 'churchedit-sql-importer'); ?></button>
                        (<?php esc_html_e('uses the SQL file selected above', 'churchedit-sql-importer'); ?>)
                    </p>
                    <div id="csi-calendar-summary"></div>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csi-calendar-from-date"><?php esc_html_e('Only import events from', 'churchedit-sql-importer'); ?></label></th>
                            <td>
                                <input type="date" id="csi-calendar-from-date" value="<?php echo esc_attr(date('Y-m-d')); ?>">
                                <p class="description"><?php esc_html_e('Events before this date are skipped entirely (not imported at all, not even as drafts). Defaults to today so old/historic events aren\'t brought in — clear the field to import everything.', 'churchedit-sql-importer'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="csi-calendar-default-status"><?php esc_html_e('Default status', 'churchedit-sql-importer'); ?></label></th>
                            <td>
                                <select id="csi-calendar-default-status">
                                    <option value="draft"><?php esc_html_e('Draft', 'churchedit-sql-importer'); ?></option>
                                    <option value="pending"><?php esc_html_e('Pending Review', 'churchedit-sql-importer'); ?></option>
                                    <option value="publish"><?php esc_html_e('Published', 'churchedit-sql-importer'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Private/restricted events in the source always import as drafts regardless of this setting.', 'churchedit-sql-importer'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="button" class="button button-primary" id="csi-import-calendar-btn" style="display:none;"><?php esc_html_e('Import Calendar', 'churchedit-sql-importer'); ?></button></p>
                    <div id="csi-calendar-progress" class="csi-progress" style="display:none;">
                        <div class="csi-progress-bar"><div class="csi-progress-bar-fill" id="csi-calendar-progress-bar-fill"></div></div>
                        <p class="csi-progress-text" id="csi-calendar-progress-text">0%</p>
                    </div>
                    <div id="csi-calendar-results-content"></div>

                    <hr>

                    <h2><?php esc_html_e('6. Import Vacancies', 'churchedit-sql-importer'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Vacancy listings live as regular pages under a folder excluded from the Pages tree above; each direct child folder becomes a "vacancy-category" term. Imports into the "vacancy" post type (octopus-vacancies plugin). Uses the SQL file parsed in step 1.', 'churchedit-sql-importer'); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csi-vacancies-folder"><?php esc_html_e('Vacancy listing folder', 'churchedit-sql-importer'); ?></label></th>
                            <td><select id="csi-vacancies-folder"><option value=""><?php esc_html_e('Parse the SQL file first', 'churchedit-sql-importer'); ?></option></select></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="csi-vacancies-default-status"><?php esc_html_e('Default status', 'churchedit-sql-importer'); ?></label></th>
                            <td>
                                <select id="csi-vacancies-default-status">
                                    <option value="draft"><?php esc_html_e('Draft', 'churchedit-sql-importer'); ?></option>
                                    <option value="pending"><?php esc_html_e('Pending Review', 'churchedit-sql-importer'); ?></option>
                                    <option value="publish"><?php esc_html_e('Published', 'churchedit-sql-importer'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p><button type="button" class="button button-primary" id="csi-import-vacancies-btn"><?php esc_html_e('Import Vacancies', 'churchedit-sql-importer'); ?></button></p>
                    <div id="csi-vacancies-progress" class="csi-progress" style="display:none;">
                        <div class="csi-progress-bar"><div class="csi-progress-bar-fill" id="csi-vacancies-progress-bar-fill"></div></div>
                        <p class="csi-progress-text" id="csi-vacancies-progress-text">0%</p>
                    </div>
                    <div id="csi-vacancies-results-content"></div>

                    <hr>

                    <h2><?php esc_html_e('7. Resolve Page Links', 'churchedit-sql-importer'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Some pages contain a "card grid" of links to other pages (ChurchEdit\'s flexContainer/blockquote pattern). These were pulled out during import and left as a placeholder, since resolving them to a WordPress post ID needs every linked page/post/vacancy to already exist. Run this after steps 3, 4, and 6 (Pages, Posts, Vacancies) are done — it replaces each placeholder with a real "Page Links" block. Links that never resolve to an imported page fall back to their original title/url/excerpt rather than being lost.', 'churchedit-sql-importer'); ?>
                    </p>
                    <p><button type="button" class="button button-primary" id="csi-resolve-page-links-btn"><?php esc_html_e('Resolve Page Links', 'churchedit-sql-importer'); ?></button></p>
                    <div id="csi-page-links-progress" class="csi-progress" style="display:none;">
                        <div class="csi-progress-bar"><div class="csi-progress-bar-fill" id="csi-page-links-progress-bar-fill"></div></div>
                        <p class="csi-progress-text" id="csi-page-links-progress-text">0%</p>
                    </div>
                    <div id="csi-page-links-results"></div>

                    <hr>

                    <h2><?php esc_html_e('8. Link Media', 'churchedit-sql-importer'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Images and documents are uploaded to the media library independently of this plugin. Each button below looks up that post type\'s pending image/document references purely by original filename in the media library and rewrites content to match — nothing is uploaded here, so upload the files first (matching their original ChurchEdit filenames) or nothing will resolve. Split by post type so each run only queries/processes one content type at a time.', 'churchedit-sql-importer'); ?>
                    </p>
                    <?php
                    $csi_link_media_types = array(
                        'page'         => __('Pages', 'churchedit-sql-importer'),
                        'post'         => __('Posts', 'churchedit-sql-importer'),
                        'vacancy'      => __('Vacancies', 'churchedit-sql-importer'),
                        'tribe_events' => __('Calendar Events', 'churchedit-sql-importer'),
                    );
                    foreach ($csi_link_media_types as $csi_type_slug => $csi_type_label) :
                        ?>
                        <h3><?php echo esc_html($csi_type_label); ?></h3>
                        <p><button type="button" class="button button-primary csi-link-media-btn" data-post-type="<?php echo esc_attr($csi_type_slug); ?>"><?php echo esc_html(sprintf(__('Link %s', 'churchedit-sql-importer'), $csi_type_label)); ?></button></p>
                        <div id="csi-media-progress-<?php echo esc_attr($csi_type_slug); ?>" class="csi-progress" style="display:none;">
                            <div class="csi-progress-bar"><div class="csi-progress-bar-fill" id="csi-media-progress-bar-fill-<?php echo esc_attr($csi_type_slug); ?>"></div></div>
                            <p class="csi-progress-text" id="csi-media-progress-text-<?php echo esc_attr($csi_type_slug); ?>">0%</p>
                        </div>
                        <div id="csi-media-results-<?php echo esc_attr($csi_type_slug); ?>"></div>
                    <?php endforeach; ?>

                    <hr>

                    <h2><?php esc_html_e('9. Import Members', 'churchedit-sql-importer'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Imports ChurchEdit\'s members table as WordPress users. Passwords are never migrated (the source hashes aren\'t reliably WP-compatible) — every account gets a random password; members will need to use "Lost your password?" to log in. No emails are sent by this step.', 'churchedit-sql-importer'); ?>
                    </p>
                    <p>
                        <button type="button" class="button" id="csi-parse-members-btn"><?php esc_html_e('Scan Members', 'churchedit-sql-importer'); ?></button>
                        (<?php esc_html_e('uses the SQL file selected above', 'churchedit-sql-importer'); ?>)
                    </p>
                    <div id="csi-members-summary"></div>
                    <p>
                        <button type="button" class="button button-primary" id="csi-import-members-btn" style="display:none;"><?php esc_html_e('Import Members', 'churchedit-sql-importer'); ?></button>
                    </p>
                    <div id="csi-members-progress" class="csi-progress" style="display:none;">
                        <div class="csi-progress-bar"><div class="csi-progress-bar-fill" id="csi-members-progress-bar-fill"></div></div>
                        <p class="csi-progress-text" id="csi-members-progress-text">0%</p>
                    </div>
                    <div id="csi-members-results-content"></div>

                    <hr>

                    <h2><?php esc_html_e('10. Compare & Update from Previous Export', 'churchedit-sql-importer'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Upload an older ChurchEdit export to see what\'s actually changed since it, per content type, then selectively update just those items — instead of blindly re-running a full import over everything. Needs step 1 (and step 5, for Calendar Events) already run against the new export loaded above, and reuses whichever folders are currently selected in the Posts and Vacancies dropdowns.', 'churchedit-sql-importer'); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csi-old-sql-zip"><?php esc_html_e('Previous export (ZIP)', 'churchedit-sql-importer'); ?></label></th>
                            <td>
                                <input type="file" id="csi-old-sql-zip" accept=".zip">
                                <button type="button" class="button button-primary" id="csi-compare-btn"><?php esc_html_e('Upload & Compare', 'churchedit-sql-importer'); ?></button>
                            </td>
                        </tr>
                    </table>
                    <div id="csi-compare-status"></div>

                    <div id="csi-compare-results" style="display:none;">
                        <?php
                        $csi_diff_buckets = array(
                            'pages'     => array(
                                'label'  => __('Pages', 'churchedit-sql-importer'),
                                'action' => 'csi_import_batch',
                                'param'  => 'refs',
                                'kind'   => 'page',
                            ),
                            'posts'     => array(
                                'label'  => __('Posts', 'churchedit-sql-importer'),
                                'action' => 'csi_import_posts_batch',
                                'param'  => 'only_page_ids',
                                'kind'   => 'page',
                            ),
                            'vacancies' => array(
                                'label'  => __('Vacancies', 'churchedit-sql-importer'),
                                'action' => 'csi_import_vacancies_batch',
                                'param'  => 'only_page_ids',
                                'kind'   => 'page',
                            ),
                            'events'    => array(
                                'label'  => __('Calendar Events', 'churchedit-sql-importer'),
                                'action' => 'csi_import_calendar_batch',
                                'param'  => 'only_event_ids',
                                'kind'   => 'event',
                            ),
                        );
                        foreach ($csi_diff_buckets as $csi_bucket_key => $csi_bucket) :
                            ?>
                            <h3><?php echo esc_html($csi_bucket['label']); ?></h3>
                            <div id="csi-diff-summary-<?php echo esc_attr($csi_bucket_key); ?>" class="csi-diff-summary"></div>
                            <div id="csi-diff-list-<?php echo esc_attr($csi_bucket_key); ?>" class="csi-diff-list"></div>
                            <p>
                                <label><input type="checkbox" class="csi-diff-select-all" data-bucket="<?php echo esc_attr($csi_bucket_key); ?>"> <?php esc_html_e('Select all changed/new', 'churchedit-sql-importer'); ?></label>
                                <button type="button" class="button button-primary csi-diff-update-btn"
                                    data-bucket="<?php echo esc_attr($csi_bucket_key); ?>"
                                    data-action="<?php echo esc_attr($csi_bucket['action']); ?>"
                                    data-param="<?php echo esc_attr($csi_bucket['param']); ?>"
                                    data-kind="<?php echo esc_attr($csi_bucket['kind']); ?>">
                                    <?php echo esc_html(sprintf(__('Update Selected %s', 'churchedit-sql-importer'), $csi_bucket['label'])); ?>
                                </button>
                            </p>
                            <div id="csi-diff-progress-<?php echo esc_attr($csi_bucket_key); ?>" class="csi-progress" style="display:none;">
                                <div class="csi-progress-bar"><div class="csi-progress-bar-fill" id="csi-diff-progress-bar-fill-<?php echo esc_attr($csi_bucket_key); ?>"></div></div>
                                <p class="csi-progress-text" id="csi-diff-progress-text-<?php echo esc_attr($csi_bucket_key); ?>">0%</p>
                            </div>
                            <div id="csi-diff-update-results-<?php echo esc_attr($csi_bucket_key); ?>"></div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }
}
