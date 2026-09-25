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
            if (resp.data.post_folders.length) {
                $postsFolder.append($('<option>').val('').text('Choose a folder…'));
                resp.data.post_folders.forEach(function (f) {
                    var label = f.title + ' (' + f.page_count + ' pages)' + (f.large_folder ? ' — large' : '');
                    $postsFolder.append($('<option>').val(f.folder_id).text(label));
                });
            } else {
                $postsFolder.append($('<option>').val('').text('No folders found'));
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
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.title || r.ref) + ': ' + esc(r.error) + '</div>');
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
            r.still_missing.forEach(function (m) {
                var isDupe = missing.some(function (x) { return x.filename === m.filename && x.post_id === m.post_id; });
                if (!isDupe) { missing.push(m); }
            });

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
                var missingList = missing.slice(0, 30).map(function (m) {
                    return esc(m.filename) + ' (in "' + esc(m.post_title || '(no title)') + '")';
                }).join(', ');
                html += '<br>Still missing from the media library (' + missing.length + '): ' + missingList;
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
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.title || r.ref) + ': ' + esc(r.error) + '</div>');
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
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.title || r.ref) + ': ' + esc(r.error) + '</div>');
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
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.title || r.ref) + ': ' + esc(r.error) + '</div>');
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

    // Parent folders above each item's title — many pages share
    // near-identical titles ("Safeguarding", "Resources"…).
    function renderBreadcrumb(item) {
        if (!item.breadcrumb || !item.breadcrumb.length) {
            return '';
        }
        return '<div class="csi-diff-breadcrumb">' + item.breadcrumb.map(esc).join(' › ') + '</div>';
    }

    // Items with no WordPress post yet can be created as a different type
    // than their section implies — a new item in a Pages folder may really
    // be a news post, and vice versa.
    function renderAddAs(bucketKey, item) {
        if (item.in_wp || bucketKey === 'events') {
            return '';
        }
        var options = bucketKey === 'vacancies' ? ['vacancy', 'page', 'post'] : (bucketKey === 'other' ? ['post', 'page'] : ['page', 'post']);
        var html = ' <label class="csi-diff-as-label">Add as <select class="csi-diff-as">';
        options.forEach(function (t) {
            html += '<option value="' + t + '"' + (t === bucketTargets[bucketKey] ? ' selected' : '') + '>' + diffTargets[t].label + '</option>';
        });
        return html + '</select></label>';
    }

    function renderDiffList(bucketKey, data, kind) {
        var summary = data.changed.length + ' changed, ' + data.added.length + ' added, ' +
            data.removed.length + ' removed, ' + data.unchanged_count + ' unchanged.';
        $('#csi-diff-summary-' + bucketKey).html('<p>' + summary + '</p>');

        var html = '';
        if (!data.changed.length && !data.added.length) {
            html += '<p><em>No changed or new items.</em></p>';
        }
        data.changed.forEach(function (item) {
            html += '<div class="csi-diff-item">' + renderBreadcrumb(item) +
                '<label><input type="checkbox" class="csi-diff-checkbox" checked' +
                ' data-ref="' + esc(item.ref) + '" data-id="' + esc(item.id) + '" data-title="' + esc(item.title || item.ref) + '"' +
                (item.wp_type ? ' data-wp-type="' + esc(item.wp_type) + '"' : '') + '> ' +
                esc(item.title || item.ref) +
                ' <span class="csi-diff-fields">(' + esc(item.fields.join(', ')) + ')</span>' +
                (item.in_wp === false ? ' <span class="csi-diff-new">(not in WordPress yet)</span>' : '') + '</label>' +
                renderAddAs(bucketKey, item) +
                ' <a href="#" class="csi-diff-view-toggle" data-kind="' + kind + '" data-id="' + esc(item.id) + '">view changes</a>' +
                '<div class="csi-diff-detail" style="display:none;"></div>' +
                '</div>';
        });
        // "Added" items get a checkbox too, same as changed ones — "Update
        // Selected" creates them (the import handlers insert whenever no
        // existing post is found for the ref/id, same as the main import
        // steps do), it doesn't only update. There's no prior version to
        // diff against, so no "view changes" link here.
        data.added.forEach(function (item) {
            html += '<div class="csi-diff-item">' + renderBreadcrumb(item) +
                '<label><input type="checkbox" class="csi-diff-checkbox" checked' +
                ' data-ref="' + esc(item.ref) + '" data-id="' + esc(item.id) + '" data-title="' + esc(item.title || item.ref) + '"' +
                (item.wp_type ? ' data-wp-type="' + esc(item.wp_type) + '"' : '') + '> ' +
                esc(item.title || item.ref) +
                ' <span class="csi-diff-fields csi-diff-new">(new)</span></label>' +
                renderAddAs(bucketKey, item) +
                '</div>';
        });
        if (data.removed.length) {
            html += '<p class="csi-diff-removed"><strong>' + data.removed.length + ' no longer in source:</strong> ' +
                data.removed.map(function (r) { return esc(r.title); }).join(', ') +
                ' — not auto-deleted; remove manually in WP if desired.</p>';
        }
        $('#csi-diff-list-' + bucketKey).html(html);
        updateDiffSelectedCount(bucketKey);
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
            renderDiffList('other', resp.data.buckets.other, 'page');
            $('#csi-compare-results').show();

            if (calendarCacheKey) {
                runCompareCalendar(oldFilePath, $btn, '');
            } else {
                $btn.prop('disabled', false);
                $('#csi-compare-status').html('<div class="notice notice-success"><p>Compared successfully.' +
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

    function updateDiffSelectedCount(bucket) {
        var $boxes = $('#csi-diff-list-' + bucket + ' .csi-diff-checkbox');
        var text = $boxes.length ? $boxes.filter(':checked').length + ' of ' + $boxes.length + ' selected' : '';
        $('#csi-diff-selected-count-' + bucket).text(text);
    }

    $(document).on('click', '.csi-diff-select', function (e) {
        e.preventDefault();
        var bucket = $(this).data('bucket');
        $('#csi-diff-list-' + bucket + ' .csi-diff-checkbox').prop('checked', $(this).data('select') === 'all');
        updateDiffSelectedCount(bucket);
    });

    $(document).on('change', '.csi-diff-checkbox', function () {
        var id = $(this).closest('.csi-diff-list').attr('id');
        updateDiffSelectedCount(id.replace('csi-diff-list-', ''));
    });

    $(document).on('click', '.csi-diff-view-toggle', function (e) {
        e.preventDefault();
        var $link = $(this);
        var $detail = $link.next('.csi-diff-detail');

        if ($detail.is(':visible')) {
            closeDiffDetail($detail);
            return;
        }
        $link.text('hide changes');

        if ($detail.data('loaded')) {
            $detail.show();
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
            $detail.html(renderTodoPanel(resp.data, kind, id)).data('loaded', true);
        }).fail(function () {
            $detail.html('<div class="notice notice-error"><p>Request failed.</p></div>');
        });
    });

    // "View changes" panel: a to-do list of what changed in ChurchEdit,
    // checked against the page as it is in WordPress now, for updating the
    // page by hand. The raw field-by-field diff is still there underneath.
    var todoLabels = {
        done: 'Already on the page',
        todo: 'To do',
        check: 'Old text not found on the page — check by hand'
    };

    function renderTodoLines(lines) {
        if (lines.length === 1) {
            return renderTodoLine(lines[0]);
        }
        return '<ul class="csi-todo-lines">' + lines.map(function (l) { return '<li>' + renderTodoLine(l) + '</li>'; }).join('') + '</ul>';
    }

    function renderTodoLine(line) {
        var html = line.text ? '<span class="csi-todo-text">' + esc(line.text) + '</span>' : '';
        if (line.links.length) {
            html += '<ul class="csi-todo-links">';
            line.links.forEach(function (link) {
                var label = link.text ? esc(link.text) + ' → ' : (link.is_file ? 'Image → ' : '');
                if (!link.is_file) {
                    html += '<li>' + label + '<code>' + esc(link.href) + '</code></li>';
                } else if (link.media_url) {
                    html += '<li>' + label + '<code>' + esc(link.media_url) + '</code> ' +
                        '<button type="button" class="button-link csi-copy" data-copy="' + esc(link.media_url) + '">Copy</button></li>';
                } else {
                    html += '<li>' + label + '<code>' + esc(link.filename) + '</code> <span class="csi-todo-missing">not in Media Library</span></li>';
                }
            });
            html += '</ul>';
        }
        return html;
    }

    function renderTodoPanel(data, kind, id) {
        var html = '<button type="button" class="button-link csi-diff-detail-close" aria-label="Close">&times; Close</button>';

        if (data.post) {
            html += '<p class="csi-todo-post"><strong>' + esc(data.post.title) + '</strong> — ' +
                '<a href="' + esc(data.post.edit) + '" target="_blank">Edit page ↗</a> · ' +
                '<a href="' + esc(data.post.view) + '" target="_blank">View ↗</a></p>';
        } else {
            html += '<p class="csi-todo-post"><em>Not imported into WordPress yet.</em></p>';
        }

        var counts = { done: 0, todo: 0, check: 0 };
        var missing = 0;
        data.todo.forEach(function (item) {
            counts[item.status]++;
            item.new.forEach(function (line) {
                line.links.forEach(function (l) { if (l.is_file && !l.media_url) { missing++; } });
            });
        });

        if (!data.todo.length) {
            html += '<p><em>No visible text or link changes — only formatting/markup differs.</em></p>';
        } else {
            html += '<p class="csi-todo-summary">' + counts.todo + ' to do, ' + counts.check + ' to check, ' + counts.done + ' already done</p>';
        }
        if (missing) {
            html += '<p><button type="button" class="button csi-todo-fetch" data-kind="' + esc(kind) + '" data-id="' + esc(id) + '">' +
                'Download ' + missing + ' missing file' + (missing > 1 ? 's' : '') + ' &amp; update links</button></p>';
        }
        html += '<div class="csi-todo-fetch-result"></div>';

        html += '<ol class="csi-todo">';
        data.todo.forEach(function (item) {
            html += '<li class="csi-todo-item csi-todo-' + item.status + '">' +
                '<span class="csi-todo-status">' + esc(todoLabels[item.status]) + '</span> ';
            if (item.type === 'change') {
                html += '<strong>Change</strong><div class="csi-todo-was">Was: ' + renderTodoLines(item.old) + '</div>' +
                    '<div class="csi-todo-now">Now: ' + renderTodoLines(item.new) + '</div>';
            } else if (item.type === 'add') {
                html += '<strong>Add' + (item.new.length > 1 ? ' ' + item.new.length + ' lines' : '') + '</strong><div class="csi-todo-now">' + renderTodoLines(item.new) + '</div>';
            } else {
                html += '<strong>Remove' + (item.old.length > 1 ? ' ' + item.old.length + ' lines' : '') + '</strong><div class="csi-todo-was">' + renderTodoLines(item.old) + '</div>';
            }
            if (item.after && item.status !== 'done') {
                html += '<div class="csi-todo-after">After: “' + esc(item.after.length > 90 ? item.after.substr(0, 90) + '…' : item.after) + '”</div>';
            }
            html += '</li>';
        });
        html += '</ol>';

        if (data.rows && data.rows.length) {
            html += '<details class="csi-todo-raw"><summary>Raw field-by-field diff</summary>';
            data.rows.forEach(function (row) {
                html += '<div class="csi-diff-field"><strong>' + esc(row.field) + '</strong>' + row.html + '</div>';
            });
            html += '</details>';
        }
        return html;
    }

    function fetchItemFiles(kind, id) {
        return $.post(csiAjax.ajaxurl, {
            action: 'csi_fetch_item_files',
            nonce: csiAjax.nonce,
            kind: kind,
            id: id,
            cache_key: (kind === 'event') ? calendarCacheKey : cacheKey,
            old_cache_key: (kind === 'event') ? oldEventsCacheKey : oldPagesCacheKey,
            source_site_url: $('#csi-source-site-url').val()
        });
    }

    function describeFetch(data) {
        var imported = 0, failed = [];
        data.files.forEach(function (f) {
            if (f.status === 'imported') { imported++; }
            if (f.status === 'failed') { failed.push(f.filename + ' (' + f.error + ')'); }
        });
        var text = imported + ' file(s) downloaded, ' + data.relinked + ' link(s) on the page updated.';
        if (failed.length) {
            text += ' Failed: ' + failed.join('; ');
        }
        return { text: text, failed: failed.length };
    }

    $(document).on('click', '.csi-todo-fetch', function () {
        var $btn = $(this).prop('disabled', true);
        var $detail = $btn.closest('.csi-diff-detail');
        var kind = $btn.data('kind');
        var id = $btn.data('id').toString();
        $detail.find('.csi-todo-fetch-result').html('<span class="spinner is-active" style="float:none;"></span> Downloading…');

        fetchItemFiles(kind, id).done(function (resp) {
            if (!resp.success) {
                $btn.prop('disabled', false);
                $detail.find('.csi-todo-fetch-result').html('<div class="notice notice-error inline"><p>' + esc(resp.data.message) + '</p></div>');
                return;
            }
            var summary = describeFetch(resp.data);
            var $raw = $detail.find('.csi-todo-raw').detach();
            $detail.html(renderTodoPanel(resp.data, kind, id)).append($raw);
            $detail.find('.csi-todo-fetch-result').html('<div class="notice ' + (summary.failed ? 'notice-warning' : 'notice-success') + ' inline"><p>' + esc(summary.text) + '</p></div>');
        }).fail(function () {
            $btn.prop('disabled', false);
            $detail.find('.csi-todo-fetch-result').html('<div class="notice notice-error inline"><p>Request failed.</p></div>');
        });
    });

    $(document).on('click', '.csi-diff-fetch-files-btn', function () {
        var $btn = $(this).prop('disabled', true);
        var bucket = $btn.data('bucket');
        var kind = $btn.data('kind');
        var $results = $('#csi-diff-update-results-' + bucket);
        var ids = [];
        $('#csi-diff-list-' + bucket + ' .csi-diff-checkbox:checked').each(function () {
            ids.push({ id: $(this).data('id').toString(), title: $(this).data('title').toString() });
        });
        if (!ids.length) {
            $btn.prop('disabled', false);
            $results.html('<div class="notice notice-warning inline"><p>Nothing selected.</p></div>');
            return;
        }

        var log = [];
        (function next(i) {
            if (i >= ids.length) {
                $btn.prop('disabled', false);
                log.push('<div class="csi-log-item">Done — ' + ids.length + ' item(s) checked.</div>');
                $results.html(log.join(''));
                return;
            }
            $results.html(log.join('') + '<div><span class="spinner is-active" style="float:none;"></span> ' + (i + 1) + ' / ' + ids.length + ': ' + esc(ids[i].title) + '</div>');
            fetchItemFiles(kind, ids[i].id).done(function (resp) {
                if (resp.success) {
                    var summary = describeFetch(resp.data);
                    if (resp.data.files.length || resp.data.relinked) {
                        log.push('<div class="csi-log-item ' + (summary.failed ? 'csi-log-error' : 'csi-log-ok') + '">' + esc(ids[i].title) + ': ' + esc(summary.text) + '</div>');
                    }
                    // Any open to-do panel for this item is now out of date.
                    $('#csi-diff-list-' + bucket + ' .csi-diff-view-toggle[data-id="' + ids[i].id + '"]').next('.csi-diff-detail').data('loaded', false);
                } else {
                    log.push('<div class="csi-log-item csi-log-error">' + esc(ids[i].title) + ': ' + esc(resp.data.message) + '</div>');
                }
            }).fail(function () {
                log.push('<div class="csi-log-item csi-log-error">' + esc(ids[i].title) + ': request failed</div>');
            }).always(function () {
                next(i + 1);
            });
        })(0);
    });

    $(document).on('click', '.csi-copy', function () {
        var $b = $(this);
        navigator.clipboard.writeText($b.data('copy')).then(function () {
            $b.text('Copied');
            setTimeout(function () { $b.text('Copy'); }, 1500);
        });
    });

    // The detail panel sits inside the scrolling .csi-diff-list, so a long
    // content diff pushes the "view changes" link out of view — the panel
    // carries its own Close button and scrolls the item back into view.
    function closeDiffDetail($detail) {
        var $link = $detail.prev('.csi-diff-view-toggle');
        $detail.hide();
        $link.text('view changes');
        var $list = $detail.closest('.csi-diff-list');
        var $item = $detail.closest('.csi-diff-item');
        if ($list.length && $item.length) {
            var itemTop = $item.position().top;
            if (itemTop < 0) {
                $list.scrollTop($list.scrollTop() + itemTop);
            }
        }
    }

    $(document).on('click', '.csi-diff-detail-close', function (e) {
        e.preventDefault();
        closeDiffDetail($(this).closest('.csi-diff-detail'));
    });

    // What each kind of WordPress item is created/updated with, and which
    // step's settings it uses. A Compare & Update section's items default to
    // its own target, but new items can be switched ("Add as").
    var diffTargets = {
        page:    { label: 'Page', action: 'csi_import_batch', param: 'refs', value: function (id) { return 'page:' + id; } },
        post:    { label: 'Post', action: 'csi_import_posts_batch', param: 'only_page_ids', value: function (id) { return id; } },
        vacancy: { label: 'Vacancy', action: 'csi_import_vacancies_batch', param: 'only_page_ids', value: function (id) { return id; } },
        event:   { label: 'Event', action: 'csi_import_calendar_batch', param: 'only_event_ids', value: function (id) { return id; } }
    };
    var bucketTargets = { pages: 'page', posts: 'post', vacancies: 'vacancy', other: 'post', events: 'event' };

    function runDiffUpdateBatch(bucket, target, values, offset, log, onDone) {
        var batchSize = 20;
        var total = values.length;
        var slice = values.slice(offset, offset + batchSize);
        var t = diffTargets[target];
        var label = t.label + 's';

        var data = {
            action: t.action,
            nonce: csiAjax.nonce,
            offset: 0,
            batch_size: batchSize,
            cache_key: (target === 'event') ? calendarCacheKey : cacheKey,
            old_cache_key: (target === 'event') ? oldEventsCacheKey : oldPagesCacheKey,
            force_replace: $('#csi-diff-force-replace-' + bucket).is(':checked') ? '1' : '0'
        };
        data[t.param] = slice.map(t.value);

        if (target === 'post') {
            data.folder_id = $('#csi-posts-folder').val();
            data.default_status = $('#csi-posts-default-status').val();
        } else if (target === 'vacancy') {
            data.folder_id = $('#csi-vacancies-folder').val();
            data.default_status = $('#csi-vacancies-default-status').val();
        } else if (target === 'event') {
            data.default_status = $('#csi-calendar-default-status').val();
        } else {
            data.default_status = $('#csi-default-status').val();
            data.block_pattern = $('#csi-block-pattern').val();
        }

        var progressBar = '#csi-diff-progress-bar-fill-' + bucket;
        var progressText = '#csi-diff-progress-text-' + bucket;
        var resultsBox = '#csi-diff-update-results-' + bucket;

        $.post(csiAjax.ajaxurl, data).done(function (resp) {
            if (!resp.success) {
                log.push('<div class="notice notice-error"><p>' + esc(label + ': ' + resp.data.message) + '</p></div>');
                $(resultsBox).html(log.join(''));
                onDone();
                return;
            }

            var newOffset = offset + slice.length;
            var pct = total > 0 ? Math.round((newOffset / total) * 100) : 100;
            $(progressBar).css('width', pct + '%');
            $(progressText).text(label + ': ' + pct + '% (' + newOffset + ' / ' + total + ')');

            resp.data.results.forEach(function (r) {
                if (r.success) {
                    log.push('<div class="csi-log-item csi-log-ok">' + esc(t.label) + ' ' + r.action + ': ' + esc(r.title || r.ref) + (r.draft ? ' (draft)' : '') + '</div>');
                } else {
                    log.push('<div class="csi-log-item csi-log-error">' + esc(r.title || r.ref) + ': ' + esc(r.error) + '</div>');
                }
            });
            $(resultsBox).html(log.join(''));

            if (newOffset < total) {
                runDiffUpdateBatch(bucket, target, values, newOffset, log, onDone);
            } else {
                onDone();
            }
        }).fail(function () {
            log.push('<div class="notice notice-error"><p>' + esc(label) + ' batch request failed at offset ' + offset + '. Click Update Selected again to resume.</p></div>');
            $(resultsBox).html(log.join(''));
            onDone();
        });
    }

    $(document).on('click', '.csi-diff-update-btn', function () {
        var bucket = $(this).data('bucket');

        // Group the ticked items by what they'll be created/updated as.
        var groups = {};
        var count = 0;
        $('#csi-diff-list-' + bucket + ' .csi-diff-checkbox:checked').each(function () {
            // Already in WordPress: always update it as the type it already
            // is. Otherwise whatever "Add as" says, or the section's default.
            var $as = $(this).closest('.csi-diff-item').find('.csi-diff-as');
            var target = $(this).data('wp-type') || ($as.length ? $as.val() : bucketTargets[bucket]);
            (groups[target] = groups[target] || []).push($(this).data('id').toString());
            count++;
        });
        if (!count) {
            return;
        }

        $('#csi-diff-progress-' + bucket).show();
        $('#csi-diff-progress-bar-fill-' + bucket).css('width', '0%');
        $('#csi-diff-progress-text-' + bucket).text('Starting...');
        $('#csi-diff-update-results-' + bucket).empty();

        var log = [];
        var targets = Object.keys(groups);
        (function next(i) {
            if (i >= targets.length) {
                $('#csi-diff-progress-text-' + bucket).text('Done — ' + count + ' item(s) processed.');
                return;
            }
            runDiffUpdateBatch(bucket, targets[i], groups[targets[i]], 0, log, function () { next(i + 1); });
        })(0);
    });

})(jQuery);
