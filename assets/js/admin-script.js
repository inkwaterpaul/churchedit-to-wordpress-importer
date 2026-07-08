(function ($) {
    'use strict';

    var cacheKey = null;
    var importTotal = 0;

    function esc(str) {
        return $('<div>').text(str == null ? '' : str).html();
    }

    function renderNode(node) {
        if (node.kind === 'folder') {
            var disabled = node.excluded ? 'disabled' : '';
            var checked = (!node.excluded && !node.large_folder) ? 'checked' : '';
            var cls = 'csi-node csi-folder' + (node.excluded ? ' csi-excluded' : '');
            var badge = '';
            if (node.excluded) {
                badge = ' <span class="csi-badge csi-badge-excluded" title="' + esc(node.exclude_reason || '') + '">excluded</span>';
            } else if (node.large_folder) {
                badge = ' <span class="csi-badge csi-badge-large" title="Large folder (' + node.page_count + ' pages) — likely a blog/news archive; unchecked by default, consider a Posts-based import instead.">large (' + node.page_count + ')</span>';
            }
            var nodeTitleBit = node.node_title ? '' : ' <span class="csi-badge csi-badge-stub" title="No source landing page — a placeholder page will be created">stub</span>';

            var html = '<div class="' + cls + '">';
            html += '<label><input type="checkbox" class="csi-folder-checkbox" data-folder-id="' + esc(node.folder_id) + '" ' + checked + ' ' + disabled + '> ';
            html += '<strong>' + esc(node.title) + '</strong>' + badge + nodeTitleBit + '</label>';
            html += '<div class="csi-children">';
            (node.children || []).forEach(function (child) {
                html += renderNode(child);
            });
            html += '</div></div>';
            return html;
        }

        // page node
        var flags = '';
        if (node.force_draft) {
            flags += ' <span class="csi-badge csi-badge-draft" title="' + esc((node.draft_reasons || []).join('; ')) + '">D</span>';
        }
        if (node.card_auto) {
            flags += ' <span class="csi-badge csi-badge-card" title="Card grid confirmed enabled in source">C</span>';
        } else if (node.card_review) {
            flags += ' <span class="csi-badge csi-badge-card-review" title="Possible card grid — needs review, see plan Phase 2">C?</span>';
        }

        return '<div class="csi-node csi-page">' +
            '<label><input type="checkbox" class="csi-page-checkbox" data-ref="' + esc(node.ref) + '" checked> ' +
            esc(node.title) + flags + '</label></div>';
    }

    function bindCascade() {
        $('#csi-tree').on('change', '.csi-folder-checkbox', function () {
            var $this = $(this);
            var checked = $this.prop('checked');
            $this.closest('.csi-folder').find('.csi-children input[type=checkbox]').prop('checked', checked);
        });
    }

    function collectSelection() {
        var folderIds = [];
        var pageRefs = [];
        $('#csi-tree .csi-folder-checkbox:checked').each(function () {
            folderIds.push($(this).data('folder-id').toString());
        });
        $('#csi-tree .csi-page-checkbox:checked').each(function () {
            var ref = $(this).data('ref').toString();
            pageRefs.push(ref.replace('page:', ''));
        });
        return { folderIds: folderIds, pageRefs: pageRefs };
    }

    function runParse(path, $btn) {
        $('#csi-sql-path').val(path);
        $('#csi-parse-status').html('<span class="spinner is-active" style="float:none;"></span> Parsing...');

        $.post(csiAjax.ajaxurl, {
            action: 'csi_parse_sql',
            nonce: csiAjax.nonce,
            file_path: path
        }).done(function (resp) {
            if ($btn) { $btn.prop('disabled', false); }
            if (!resp.success) {
                $('#csi-parse-status').html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            cacheKey = resp.data.cache_key;
            $('#csi-parse-status').html('<div class="notice notice-success"><p>Parsed successfully.</p></div>');

            var s = resp.data.summary;
            $('#csi-summary').show().html(
                '<p><strong>' + s.folders + '</strong> folders, <strong>' + s.pages + '</strong> pages. ' +
                s.excluded_folders + ' feature folders excluded. ' +
                s.draft_pages + ' pages will import as drafts (restricted/hidden in source). ' +
                s.card_auto + ' confirmed card-grid pages, ' + s.card_review + ' flagged for review (Phase 2).</p>'
            );

            var treeHtml = '';
            resp.data.tree.forEach(function (node) {
                treeHtml += renderNode(node);
            });
            $('#csi-tree').html(treeHtml);

            $('#csi-import-options').show();
            $('#csi-tree-wrap').show();

            var $postsFolder = $('#csi-posts-folder').empty();
            if (resp.data.large_folders.length) {
                resp.data.large_folders.forEach(function (f) {
                    $postsFolder.append($('<option>').val(f.folder_id).text(f.title + ' (' + f.page_count + ' pages)'));
                });
            } else {
                $postsFolder.append($('<option>').val('').text('No large folders found'));
            }

            var $vacanciesFolder = $('#csi-vacancies-folder').empty();
            if (resp.data.vacancy_folders.length) {
                resp.data.vacancy_folders.forEach(function (f) {
                    $vacanciesFolder.append($('<option>').val(f.folder_id).text(f.title + ' (' + f.item_count + ' vacancies)'));
                });
            } else {
                $vacanciesFolder.append($('<option>').val('').text('No vacancy listing folder found'));
            }
        }).fail(function () {
            if ($btn) { $btn.prop('disabled', false); }
            $('#csi-parse-status').html('<div class="notice notice-error"><p>Request failed.</p></div>');
        });
    }

    $(document).on('click', '#csi-upload-parse-btn', function () {
        var fileInput = document.getElementById('csi-sql-zip');
        if (!fileInput.files.length) {
            $('#csi-parse-status').html('<div class="notice notice-error"><p>Choose a ZIP file first.</p></div>');
            return;
        }

        var $btn = $(this).prop('disabled', true);
        $('#csi-parse-status').html('<span class="spinner is-active" style="float:none;"></span> Uploading & extracting...');

        var formData = new FormData();
        formData.append('action', 'csi_upload_sql_zip');
        formData.append('nonce', csiAjax.nonce);
        formData.append('sql_zip', fileInput.files[0]);

        $.ajax({
            url: csiAjax.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).done(function (resp) {
            if (!resp.success) {
                $btn.prop('disabled', false);
                $('#csi-parse-status').html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            runParse(resp.data.file_path, $btn);
        }).fail(function () {
            $btn.prop('disabled', false);
            $('#csi-parse-status').html('<div class="notice notice-error"><p>Upload request failed.</p></div>');
        });
    });

    bindCascade();

    function runImportBatch(offset, folderIds, pageRefs, options, log) {
        $.post(csiAjax.ajaxurl, {
            action: 'csi_import_batch',
            nonce: csiAjax.nonce,
            cache_key: cacheKey,
            folder_ids: folderIds,
            page_ids: pageRefs,
            offset: offset,
            batch_size: 20,
            default_status: options.defaultStatus,
            block_pattern: options.blockPattern
        }).done(function (resp) {
            if (!resp.success) {
                log.push('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                $('#csi-results-content').html(log.join(''));
                return;
            }

            importTotal = resp.data.total;
            var pct = importTotal > 0 ? Math.round((resp.data.next_offset / importTotal) * 100) : 100;
            $('#csi-progress-bar-fill').css('width', pct + '%');
            $('#csi-progress-text').text(pct + '% (' + resp.data.next_offset + ' / ' + importTotal + ')');

            resp.data.results.forEach(function (r) {
                if (r.success) {
                    log.push('<div class="csi-log-item csi-log-ok">' + r.action + ': ' + esc(r.title || r.ref) + (r.draft ? ' (draft)' : '') + '</div>');
                } else {
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.ref) + ': ' + esc(r.error) + '</div>');
                }
            });
            $('#csi-results-content').html(log.join(''));

            if (!resp.data.done) {
                runImportBatch(resp.data.next_offset, folderIds, pageRefs, options, log);
            } else {
                $('#csi-progress-text').text('Done — ' + importTotal + ' items processed.');
            }
        }).fail(function () {
            log.push('<div class="notice notice-error"><p>Batch request failed at offset ' + offset + '. Click Import Selected again to resume (already-imported items are updated, not duplicated).</p></div>');
            $('#csi-results-content').html(log.join(''));
        });
    }

    $(document).on('click', '#csi-import-btn', function () {
        if (!cacheKey) {
            return;
        }
        var sel = collectSelection();
        var options = {
            defaultStatus: $('#csi-default-status').val(),
            blockPattern: $('#csi-block-pattern').val()
        };

        $('#csi-progress').show();
        $('#csi-progress-bar-fill').css('width', '0%');
        $('#csi-progress-text').text('Starting...');
        $('#csi-results').show();
        $('#csi-results-content').empty();

        runImportBatch(0, sel.folderIds, sel.pageRefs, options, []);
    });

    // ---- Step 7: Resolve Page Links ----

    function runResolvePageLinksBatch(excludeIds, processedSoFar, totals) {
        $.post(csiAjax.ajaxurl, {
            action: 'csi_resolve_page_links_batch',
            nonce: csiAjax.nonce,
            batch_size: 10,
            exclude_ids: excludeIds
        }).done(function (resp) {
            if (!resp.success) {
                $('#csi-page-links-results').html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            var r = resp.data.results;
            totals.links += r.links_resolved;
            totals.posts += r.posts_updated;
            processedSoFar += r.batch_count;
            excludeIds = excludeIds.concat(r.post_ids);

            $('#csi-page-links-progress-text').text(processedSoFar + ' post(s) checked — ' + totals.links + ' link(s) resolved across ' + totals.posts + ' post(s).');
            $('#csi-page-links-progress-bar-fill').css('width', (resp.data.done ? 100 : 50) + '%');

            if (!resp.data.done) {
                runResolvePageLinksBatch(excludeIds, processedSoFar, totals);
                return;
            }

            $('#csi-page-links-results').html('<div class="notice notice-success"><p>Checked ' + processedSoFar + ' post(s). ' +
                totals.links + ' link(s) resolved across ' + totals.posts + ' post(s).</p></div>');
        }).fail(function () {
            $('#csi-page-links-results').html('<div class="notice notice-error"><p>Batch request failed. Click the button again to resume — already-resolved posts won\'t be redone.</p></div>');
        });
    }

    $(document).on('click', '#csi-resolve-page-links-btn', function () {
        $('#csi-page-links-progress').show();
        $('#csi-page-links-progress-bar-fill').css('width', '0%');
        $('#csi-page-links-progress-text').text('Starting...');
        $('#csi-page-links-results').empty();

        runResolvePageLinksBatch([], 0, {links: 0, posts: 0});
    });

    // ---- Step 8: Link Media (pure filename lookup, no uploading — one post type per run) ----

    function runLinkMediaBatch(postType, excludeIds, processedSoFar, totals, missing) {
        var progressBar  = '#csi-media-progress-bar-fill-' + postType;
        var progressText = '#csi-media-progress-text-' + postType;
        var resultsBox   = '#csi-media-results-' + postType;

        $.post(csiAjax.ajaxurl, {
            action: 'csi_link_media_batch',
            nonce: csiAjax.nonce,
            post_type: postType,
            batch_size: 10,
            exclude_ids: excludeIds
        }).done(function (resp) {
            if (!resp.success) {
                $(resultsBox).html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            var r = resp.data.results;
            totals.images += r.images_updated;
            totals.docs += r.docs_updated;
            totals.pages += r.pages_updated;
            processedSoFar += r.batch_count;
            excludeIds = excludeIds.concat(r.post_ids);
            r.still_missing.forEach(function (m) { if (missing.indexOf(m) === -1) missing.push(m); });

            var pct = r.total_pending > 0 ? Math.min(100, Math.round((processedSoFar / r.total_pending) * 100)) : 100;
            $(progressText).text(processedSoFar + ' / ' + r.total_pending + ' post(s) checked — ' +
                totals.images + ' image(s), ' + totals.docs + ' document(s) linked.');
            $(progressBar).css('width', (resp.data.done ? 100 : pct) + '%');

            if (!resp.data.done) {
                runLinkMediaBatch(postType, excludeIds, processedSoFar, totals, missing);
                return;
            }

            var html = '<div class="notice notice-success"><p>' +
                'Checked ' + processedSoFar + ' post(s). ' +
                totals.images + ' image(s), ' + totals.docs + ' document(s) linked across ' + totals.pages + ' post(s).';
            if (missing.length) {
                html += '<br>Still missing from the media library (' + missing.length + '): ' + esc(missing.slice(0, 30).join(', '));
            }
            html += '</p></div>';
            $(resultsBox).html(html);
        }).fail(function () {
            $(resultsBox).html('<div class="notice notice-error"><p>Batch request failed. Click the button again to resume — already-resolved items won\'t be redone.</p></div>');
        });
    }

    $(document).on('click', '.csi-link-media-btn', function () {
        var postType = $(this).data('post-type');
        $('#csi-media-progress-' + postType).show();
        $('#csi-media-progress-bar-fill-' + postType).css('width', '0%');
        $('#csi-media-progress-text-' + postType).text('Starting...');
        $('#csi-media-results-' + postType).empty();

        runLinkMediaBatch(postType, [], 0, {images: 0, docs: 0, pages: 0}, []);
    });

    // ---- Members ----
    var membersCacheKey = null;
    var membersTotal = 0;

    $(document).on('click', '#csi-parse-members-btn', function () {
        var path = $('#csi-sql-path').val();
        if (!path) {
            $('#csi-members-summary').html('<div class="notice notice-error"><p>Select the SQL file in step 1 first.</p></div>');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        $('#csi-members-summary').html('<span class="spinner is-active" style="float:none;"></span> Scanning...');

        $.post(csiAjax.ajaxurl, {
            action: 'csi_parse_members',
            nonce: csiAjax.nonce,
            file_path: path
        }).done(function (resp) {
            $btn.prop('disabled', false);
            if (!resp.success) {
                $('#csi-members-summary').html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            membersCacheKey = resp.data.cache_key;
            $('#csi-members-summary').html(
                '<p>' + resp.data.total + ' total member rows, <strong>' + resp.data.live + '</strong> are live (not deleted, have email+username). ' +
                resp.data.admin_tier + ' will be created as Editor, ' + resp.data.default_tier + ' as Contributor.</p>'
            );
            $('#csi-import-members-btn').show();
        }).fail(function () {
            $btn.prop('disabled', false);
            $('#csi-members-summary').html('<div class="notice notice-error"><p>Request failed.</p></div>');
        });
    });

    function runMembersImportBatch(offset, log) {
        $.post(csiAjax.ajaxurl, {
            action: 'csi_import_members_batch',
            nonce: csiAjax.nonce,
            cache_key: membersCacheKey,
            offset: offset,
            batch_size: 20
        }).done(function (resp) {
            if (!resp.success) {
                log.push('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                $('#csi-members-results-content').html(log.join(''));
                return;
            }

            membersTotal = resp.data.total;
            var pct = membersTotal > 0 ? Math.round((resp.data.next_offset / membersTotal) * 100) : 100;
            $('#csi-members-progress-bar-fill').css('width', pct + '%');
            $('#csi-members-progress-text').text(pct + '% (' + resp.data.next_offset + ' / ' + membersTotal + ')');

            resp.data.results.forEach(function (r) {
                if (r.success) {
                    var label = r.action === 'created' ? ('created ' + esc(r.login) + ' (' + esc(r.role) + ')') : (r.action + (r.note ? ': ' + esc(r.note) : ''));
                    log.push('<div class="csi-log-item csi-log-ok">member ' + esc(r.member_id) + ': ' + label + '</div>');
                } else {
                    log.push('<div class="csi-log-item csi-log-error">member ' + esc(r.member_id) + ': ' + esc(r.error) + '</div>');
                }
            });
            $('#csi-members-results-content').html(log.join(''));

            if (!resp.data.done) {
                runMembersImportBatch(resp.data.next_offset, log);
            } else {
                $('#csi-members-progress-text').text('Done — ' + membersTotal + ' members processed.');
            }
        }).fail(function () {
            log.push('<div class="notice notice-error"><p>Batch request failed at offset ' + offset + '. Click Import Members again to resume.</p></div>');
            $('#csi-members-results-content').html(log.join(''));
        });
    }

    $(document).on('click', '#csi-import-members-btn', function () {
        if (!membersCacheKey) {
            return;
        }
        $('#csi-members-progress').show();
        $('#csi-members-progress-bar-fill').css('width', '0%');
        $('#csi-members-progress-text').text('Starting...');
        $('#csi-members-results-content').empty();

        runMembersImportBatch(0, []);
    });

    // ---- Import Folder as Posts ----
    function runPostsImportBatch(folderId, offset, log) {
        $.post(csiAjax.ajaxurl, {
            action: 'csi_import_posts_batch',
            nonce: csiAjax.nonce,
            cache_key: cacheKey,
            folder_id: folderId,
            offset: offset,
            batch_size: 20,
            default_status: $('#csi-posts-default-status').val()
        }).done(function (resp) {
            if (!resp.success) {
                log.push('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                $('#csi-posts-results-content').html(log.join(''));
                return;
            }

            var pct = resp.data.total > 0 ? Math.round((resp.data.next_offset / resp.data.total) * 100) : 100;
            $('#csi-posts-progress-bar-fill').css('width', pct + '%');
            $('#csi-posts-progress-text').text(pct + '% (' + resp.data.next_offset + ' / ' + resp.data.total + ')');

            resp.data.results.forEach(function (r) {
                if (r.success) {
                    log.push('<div class="csi-log-item csi-log-ok">' + r.action + ': ' + esc(r.title || r.ref) + (r.draft ? ' (draft)' : '') + '</div>');
                } else {
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.ref) + ': ' + esc(r.error) + '</div>');
                }
            });
            $('#csi-posts-results-content').html(log.join(''));

            if (!resp.data.done) {
                runPostsImportBatch(folderId, resp.data.next_offset, log);
            } else {
                $('#csi-posts-progress-text').text('Done — ' + resp.data.total + ' items processed.');
            }
        }).fail(function () {
            log.push('<div class="notice notice-error"><p>Batch request failed at offset ' + offset + '. Click Import Folder as Posts again to resume.</p></div>');
            $('#csi-posts-results-content').html(log.join(''));
        });
    }

    $(document).on('click', '#csi-import-posts-btn', function () {
        var folderId = $('#csi-posts-folder').val();
        if (!cacheKey || !folderId) {
            $('#csi-posts-results-content').html('<div class="notice notice-error"><p>Parse the SQL file first and choose a folder.</p></div>');
            return;
        }
        $('#csi-posts-progress').show();
        $('#csi-posts-progress-bar-fill').css('width', '0%');
        $('#csi-posts-progress-text').text('Starting...');
        $('#csi-posts-results-content').empty();

        runPostsImportBatch(folderId, 0, []);
    });

    // ---- Vacancies ----
    function runVacanciesImportBatch(folderId, offset, log) {
        $.post(csiAjax.ajaxurl, {
            action: 'csi_import_vacancies_batch',
            nonce: csiAjax.nonce,
            cache_key: cacheKey,
            folder_id: folderId,
            offset: offset,
            batch_size: 20,
            default_status: $('#csi-vacancies-default-status').val()
        }).done(function (resp) {
            if (!resp.success) {
                log.push('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                $('#csi-vacancies-results-content').html(log.join(''));
                return;
            }

            var pct = resp.data.total > 0 ? Math.round((resp.data.next_offset / resp.data.total) * 100) : 100;
            $('#csi-vacancies-progress-bar-fill').css('width', pct + '%');
            $('#csi-vacancies-progress-text').text(pct + '% (' + resp.data.next_offset + ' / ' + resp.data.total + ')');

            resp.data.results.forEach(function (r) {
                if (r.success) {
                    var cat = r.category ? ' [' + esc(r.category) + ']' : '';
                    var closing = r.closing_date ? ' — closing ' + esc(r.closing_date) : ' — no closing date found';
                    log.push('<div class="csi-log-item csi-log-ok">' + r.action + ': ' + esc(r.title || r.ref) + cat + closing + (r.draft ? ' (draft)' : '') + '</div>');
                } else {
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.ref) + ': ' + esc(r.error) + '</div>');
                }
            });
            $('#csi-vacancies-results-content').html(log.join(''));

            if (!resp.data.done) {
                runVacanciesImportBatch(folderId, resp.data.next_offset, log);
            } else {
                $('#csi-vacancies-progress-text').text('Done — ' + resp.data.total + ' items processed.');
            }
        }).fail(function () {
            log.push('<div class="notice notice-error"><p>Batch request failed at offset ' + offset + '. Click Import Vacancies again to resume.</p></div>');
            $('#csi-vacancies-results-content').html(log.join(''));
        });
    }

    $(document).on('click', '#csi-import-vacancies-btn', function () {
        var folderId = $('#csi-vacancies-folder').val();
        if (!cacheKey || !folderId) {
            $('#csi-vacancies-results-content').html('<div class="notice notice-error"><p>Parse the SQL file first and choose a folder.</p></div>');
            return;
        }
        $('#csi-vacancies-progress').show();
        $('#csi-vacancies-progress-bar-fill').css('width', '0%');
        $('#csi-vacancies-progress-text').text('Starting...');
        $('#csi-vacancies-results-content').empty();

        runVacanciesImportBatch(folderId, 0, []);
    });

    // ---- Calendar ----
    var calendarCacheKey = null;

    $(document).on('click', '#csi-parse-calendar-btn', function () {
        var path = $('#csi-sql-path').val();
        if (!path) {
            $('#csi-calendar-summary').html('<div class="notice notice-error"><p>Select the SQL file in step 1 first.</p></div>');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        $('#csi-calendar-summary').html('<span class="spinner is-active" style="float:none;"></span> Scanning...');

        $.post(csiAjax.ajaxurl, {
            action: 'csi_parse_calendar',
            nonce: csiAjax.nonce,
            file_path: path
        }).done(function (resp) {
            $btn.prop('disabled', false);
            if (!resp.success) {
                $('#csi-calendar-summary').html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            calendarCacheKey = resp.data.cache_key;
            $('#csi-calendar-summary').html('<p>' + resp.data.total + ' events, ' + resp.data.locations + ' locations found.</p>');
            $('#csi-import-calendar-btn').show();
        }).fail(function () {
            $btn.prop('disabled', false);
            $('#csi-calendar-summary').html('<div class="notice notice-error"><p>Request failed.</p></div>');
        });
    });

    function runCalendarImportBatch(offset, log) {
        $.post(csiAjax.ajaxurl, {
            action: 'csi_import_calendar_batch',
            nonce: csiAjax.nonce,
            cache_key: calendarCacheKey,
            offset: offset,
            batch_size: 10,
            default_status: $('#csi-calendar-default-status').val(),
            from_date: $('#csi-calendar-from-date').val()
        }).done(function (resp) {
            if (!resp.success) {
                log.push('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                $('#csi-calendar-results-content').html(log.join(''));
                return;
            }

            var pct = resp.data.total > 0 ? Math.round((resp.data.next_offset / resp.data.total) * 100) : 100;
            $('#csi-calendar-progress-bar-fill').css('width', pct + '%');
            $('#csi-calendar-progress-text').text(pct + '% (' + resp.data.next_offset + ' / ' + resp.data.total + ')');

            resp.data.results.forEach(function (r) {
                if (r.success) {
                    log.push('<div class="csi-log-item csi-log-ok">' + r.action + ': ' + esc(r.title || r.ref) + (r.draft ? ' (draft)' : '') + '</div>');
                } else {
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.ref) + ': ' + esc(r.error) + '</div>');
                }
            });
            $('#csi-calendar-results-content').html(log.join(''));

            if (!resp.data.done) {
                runCalendarImportBatch(resp.data.next_offset, log);
            } else {
                $('#csi-calendar-progress-text').text('Done — ' + resp.data.total + ' events processed.');
            }
        }).fail(function () {
            log.push('<div class="notice notice-error"><p>Batch request failed at offset ' + offset + '. Click Import Calendar again to resume.</p></div>');
            $('#csi-calendar-results-content').html(log.join(''));
        });
    }

    $(document).on('click', '#csi-import-calendar-btn', function () {
        if (!calendarCacheKey) {
            return;
        }
        $('#csi-calendar-progress').show();
        $('#csi-calendar-progress-bar-fill').css('width', '0%');
        $('#csi-calendar-progress-text').text('Starting...');
        $('#csi-calendar-results-content').empty();

        runCalendarImportBatch(0, []);
    });

    // ---- Step 10: Compare & Update from Previous Export ----
    var oldPagesCacheKey = null;
    var oldEventsCacheKey = null;

    function renderDiffList(bucketKey, data, kind) {
        var summary = data.changed.length + ' changed, ' + data.added.length + ' added, ' +
            data.removed.length + ' removed, ' + data.unchanged_count + ' unchanged.';
        $('#csi-diff-summary-' + bucketKey).html('<p>' + summary + '</p>');

        var html = '';
        if (!data.changed.length) {
            html += '<p><em>No changed items.</em></p>';
        }
        data.changed.forEach(function (item) {
            html += '<div class="csi-diff-item">' +
                '<label><input type="checkbox" class="csi-diff-checkbox" checked' +
                ' data-ref="' + esc(item.ref) + '" data-id="' + esc(item.id) + '"> ' +
                esc(item.title || item.ref) +
                ' <span class="csi-diff-fields">(' + esc(item.fields.join(', ')) + ')</span></label>' +
                ' <a href="#" class="csi-diff-view-toggle" data-kind="' + kind + '" data-id="' + esc(item.id) + '">view changes</a>' +
                '<div class="csi-diff-detail" style="display:none;"></div>' +
                '</div>';
        });
        if (data.added.length) {
            html += '<p class="csi-diff-added"><strong>' + data.added.length + ' new:</strong> ' +
                data.added.map(function (a) { return esc(a.title || a.ref); }).join(', ') +
                ' — will be created by the import step above, not by this compare step.</p>';
        }
        if (data.removed.length) {
            html += '<p class="csi-diff-removed"><strong>' + data.removed.length + ' no longer in source:</strong> ' +
                data.removed.map(function (r) { return esc(r.title); }).join(', ') +
                ' — not auto-deleted; remove manually in WP if desired.</p>';
        }
        $('#csi-diff-list-' + bucketKey).html(html);
    }

    function runCompareCalendar(oldFilePath, $btn, extraNote) {
        $.post(csiAjax.ajaxurl, {
            action: 'csi_compare_calendar',
            nonce: csiAjax.nonce,
            cache_key: calendarCacheKey,
            old_file_path: oldFilePath
        }).done(function (resp) {
            $btn.prop('disabled', false);
            if (!resp.success) {
                $('#csi-compare-status').html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            oldEventsCacheKey = resp.data.old_events_cache_key;
            renderDiffList('events', resp.data, 'event');
            $('#csi-compare-results').show();
            $('#csi-compare-status').html('<div class="notice notice-success"><p>Compared successfully.' + esc(extraNote) + '</p></div>');
        }).fail(function () {
            $btn.prop('disabled', false);
            $('#csi-compare-status').html('<div class="notice notice-error"><p>Calendar compare request failed.</p></div>');
        });
    }

    function runComparePages(oldFilePath, $btn) {
        $.post(csiAjax.ajaxurl, {
            action: 'csi_compare_pages',
            nonce: csiAjax.nonce,
            cache_key: cacheKey,
            old_file_path: oldFilePath,
            posts_folder_id: $('#csi-posts-folder').val(),
            vacancies_folder_id: $('#csi-vacancies-folder').val()
        }).done(function (resp) {
            if (!resp.success) {
                $btn.prop('disabled', false);
                $('#csi-compare-status').html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            oldPagesCacheKey = resp.data.old_pages_cache_key;
            renderDiffList('pages', resp.data.buckets.pages, 'page');
            renderDiffList('posts', resp.data.buckets.posts, 'page');
            renderDiffList('vacancies', resp.data.buckets.vacancies, 'page');
            $('#csi-compare-results').show();

            var otherNote = resp.data.other_changed
                ? (' ' + resp.data.other_changed + ' other changed item(s) fell outside the Pages/Posts/Vacancies selections and aren\'t shown.')
                : '';

            if (calendarCacheKey) {
                runCompareCalendar(oldFilePath, $btn, otherNote);
            } else {
                $btn.prop('disabled', false);
                $('#csi-compare-status').html('<div class="notice notice-success"><p>Compared successfully.' + esc(otherNote) +
                    ' Run "Scan Calendar" (step 5) then Compare again to also include Calendar Events.</p></div>');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $('#csi-compare-status').html('<div class="notice notice-error"><p>Compare request failed.</p></div>');
        });
    }

    $(document).on('click', '#csi-compare-btn', function () {
        var fileInput = document.getElementById('csi-old-sql-zip');
        if (!fileInput.files.length) {
            $('#csi-compare-status').html('<div class="notice notice-error"><p>Choose the previous export ZIP first.</p></div>');
            return;
        }
        if (!cacheKey) {
            $('#csi-compare-status').html('<div class="notice notice-error"><p>Parse the new export in step 1 first.</p></div>');
            return;
        }

        var $btn = $(this).prop('disabled', true);
        $('#csi-compare-status').html('<span class="spinner is-active" style="float:none;"></span> Uploading & comparing...');

        var formData = new FormData();
        formData.append('action', 'csi_upload_sql_zip');
        formData.append('nonce', csiAjax.nonce);
        formData.append('sql_zip', fileInput.files[0]);

        $.ajax({
            url: csiAjax.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).done(function (resp) {
            if (!resp.success) {
                $btn.prop('disabled', false);
                $('#csi-compare-status').html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            runComparePages(resp.data.file_path, $btn);
        }).fail(function () {
            $btn.prop('disabled', false);
            $('#csi-compare-status').html('<div class="notice notice-error"><p>Upload request failed.</p></div>');
        });
    });

    $(document).on('change', '.csi-diff-select-all', function () {
        var bucket = $(this).data('bucket');
        $('#csi-diff-list-' + bucket + ' .csi-diff-checkbox').prop('checked', $(this).prop('checked'));
    });

    $(document).on('click', '.csi-diff-view-toggle', function (e) {
        e.preventDefault();
        var $link = $(this);
        var $detail = $link.next('.csi-diff-detail');

        if ($detail.data('loaded')) {
            $detail.toggle();
            return;
        }

        var kind = $link.data('kind');
        var id = $link.data('id').toString();
        var newCacheKey = (kind === 'event') ? calendarCacheKey : cacheKey;
        var oldCacheKey = (kind === 'event') ? oldEventsCacheKey : oldPagesCacheKey;

        $detail.html('<span class="spinner is-active" style="float:none;"></span>').show();

        $.post(csiAjax.ajaxurl, {
            action: 'csi_diff_view_item',
            nonce: csiAjax.nonce,
            kind: kind,
            cache_key: newCacheKey,
            old_cache_key: oldCacheKey,
            id: id
        }).done(function (resp) {
            if (!resp.success) {
                $detail.html('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            var html = '';
            resp.data.rows.forEach(function (row) {
                html += '<div class="csi-diff-field"><strong>' + esc(row.field) + '</strong>' + row.html + '</div>';
            });
            $detail.html(html).data('loaded', true);
        }).fail(function () {
            $detail.html('<div class="notice notice-error"><p>Request failed.</p></div>');
        });
    });

    function runDiffUpdateBatch(bucket, action, param, kind, values, offset, log) {
        var batchSize = 20;
        var total = values.length;
        var slice = values.slice(offset, offset + batchSize);

        var data = {
            action: action,
            nonce: csiAjax.nonce,
            offset: 0,
            batch_size: batchSize,
            cache_key: (kind === 'event') ? calendarCacheKey : cacheKey
        };
        data[param] = slice;

        if (bucket === 'posts') {
            data.folder_id = $('#csi-posts-folder').val();
            data.default_status = $('#csi-posts-default-status').val();
        } else if (bucket === 'vacancies') {
            data.folder_id = $('#csi-vacancies-folder').val();
            data.default_status = $('#csi-vacancies-default-status').val();
        } else if (bucket === 'events') {
            data.default_status = $('#csi-calendar-default-status').val();
        } else if (bucket === 'pages') {
            data.default_status = $('#csi-default-status').val();
            data.block_pattern = $('#csi-block-pattern').val();
        }

        var progressBar = '#csi-diff-progress-bar-fill-' + bucket;
        var progressText = '#csi-diff-progress-text-' + bucket;
        var resultsBox = '#csi-diff-update-results-' + bucket;

        $.post(csiAjax.ajaxurl, data).done(function (resp) {
            if (!resp.success) {
                log.push('<div class="notice notice-error"><p>' + esc(resp.data.message) + '</p></div>');
                $(resultsBox).html(log.join(''));
                return;
            }

            var newOffset = offset + slice.length;
            var pct = total > 0 ? Math.round((newOffset / total) * 100) : 100;
            $(progressBar).css('width', pct + '%');
            $(progressText).text(pct + '% (' + newOffset + ' / ' + total + ')');

            resp.data.results.forEach(function (r) {
                if (r.success) {
                    log.push('<div class="csi-log-item csi-log-ok">' + r.action + ': ' + esc(r.title || r.ref) + (r.draft ? ' (draft)' : '') + '</div>');
                } else {
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.ref) + ': ' + esc(r.error) + '</div>');
                }
            });
            $(resultsBox).html(log.join(''));

            if (newOffset < total) {
                runDiffUpdateBatch(bucket, action, param, kind, values, newOffset, log);
            } else {
                $(progressText).text('Done — ' + total + ' item(s) processed.');
            }
        }).fail(function () {
            log.push('<div class="notice notice-error"><p>Batch request failed at offset ' + offset + '. Click Update Selected again to resume.</p></div>');
            $(resultsBox).html(log.join(''));
        });
    }

    $(document).on('click', '.csi-diff-update-btn', function () {
        var $btn = $(this);
        var bucket = $btn.data('bucket');
        var action = $btn.data('action');
        var param = $btn.data('param');
        var kind = $btn.data('kind');

        var values = [];
        $('#csi-diff-list-' + bucket + ' .csi-diff-checkbox:checked').each(function () {
            values.push((param === 'refs' ? $(this).data('ref') : $(this).data('id')).toString());
        });
        if (!values.length) {
            return;
        }

        $('#csi-diff-progress-' + bucket).show();
        $('#csi-diff-progress-bar-fill-' + bucket).css('width', '0%');
        $('#csi-diff-progress-text-' + bucket).text('Starting...');
        $('#csi-diff-update-results-' + bucket).empty();

        runDiffUpdateBatch(bucket, action, param, kind, values, 0, []);
    });

})(jQuery);
