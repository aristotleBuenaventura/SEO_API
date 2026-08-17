(function ($) {
    'use strict';

    function getForm() {
        return {
            brand: $('#usdr-brand').val() || '',
            language: $('#usdr-language').val() || '',
            oldDomain: $('#usdr-old-domain').val().trim(),
            newDomain: $('#usdr-new-domain').val().trim(),
        };
    }

    function formPayload(extra) {
        const form = getForm();
        return $.extend({
            nonce: USDR.nonce,
            brand: form.brand,
            language: form.language,
            old_domain: form.oldDomain,
            new_domain: form.newDomain,
        }, extra || {});
    }

    function setStatus(message, type, details) {
        const $status = $('#usdr-status');
        $status.removeClass('is-info is-success is-error');
        if (type) {
            $status.addClass('is-' + type);
        }

        let html = '';
        if (message) {
            html += '<div class="usdr-flash is-' + (type || 'info') + '">' + $('<div>').text(message).html();
            if (details) {
                html += '<code class="usdr-flash-details">' + $('<div>').text(details).html() + '</code>';
            }
            html += '</div>';
        }

        $status.html(html);
    }

    function parseAjaxError(xhr, fallback) {
        if (xhr && xhr.responseJSON) {
            if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
                return xhr.responseJSON.data.message;
            }
            if (xhr.responseJSON.message) {
                return xhr.responseJSON.message;
            }
        }

        if (xhr && xhr.status === 403) {
            return 'Permission denied. Your user account may not have access to manage URL Shortify links.';
        }

        if (xhr && xhr.status === 0) {
            return 'Network error or request blocked. Check browser console and security plugins.';
        }

        if (xhr && xhr.responseText === '0') {
            return 'WordPress AJAX returned 0. The plugin action may not be registered. Re-activate the plugin and try again.';
        }

        if (xhr && xhr.responseText === '-1') {
            return 'Security check failed. Refresh the page and try again.';
        }

        if (xhr && xhr.status) {
            return (fallback || 'Request failed.') + ' (HTTP ' + xhr.status + ')';
        }

        return fallback || 'Request failed.';
    }

    function isReady() {
        return !!(window.USDR && USDR.diagnostics && USDR.diagnostics.ready);
    }

    function isFormValid() {
        const form = getForm();
        return !!(form.brand && form.language && form.oldDomain && form.newDomain);
    }

    function confirmDialog(title, message) {
        return new Promise(function (resolve) {
            const $modal = $('#usdr-modal');
            $('#usdr-modal-title').text(title || USDR.i18n.confirmTitle);
            $('#usdr-modal-message').text(message || USDR.i18n.confirm);
            $('#usdr-modal-cancel').text(USDR.i18n.confirmCancel);
            $('#usdr-modal-confirm').text(USDR.i18n.confirmOk);
            $modal.removeAttr('hidden').attr('aria-hidden', 'false').addClass('is-open');
            $('body').addClass('usdr-modal-open');

            function close(result) {
                $modal.attr('hidden', 'hidden').attr('aria-hidden', 'true').removeClass('is-open');
                $('body').removeClass('usdr-modal-open');
                $('#usdr-modal-confirm, #usdr-modal-cancel, [data-usdr-modal-close]').off('.usdrModal');
                $(document).off('keydown.usdrModal');
                resolve(result);
            }

            $('#usdr-modal-confirm').on('click.usdrModal', function () {
                close(true);
            });
            $('#usdr-modal-cancel, [data-usdr-modal-close]').on('click.usdrModal', function () {
                close(false);
            });
            $(document).on('keydown.usdrModal', function (e) {
                if (e.key === 'Escape') {
                    close(false);
                }
            });
        });
    }

    function renderSummary(data) {
        const $summary = $('#usdr-summary');
        const $section = $('#usdr-results-section');

        if (!data || typeof data.count === 'undefined') {
            $summary.empty();
            $section.addClass('is-hidden');
            return;
        }

        const brand = $('<div>').text(data.brand || '').html();
        const language = $('<div>').text(data.language || '').html();
        const oldDomain = $('<div>').text(data.old_domain).html();
        const newDomain = $('<div>').text(data.new_domain).html();
        const sheetSlugs = typeof data.sheet_slugs !== 'undefined' ? data.sheet_slugs : 0;

        $summary.html(
            '<div class="usdr-summary-stats">' +
            '<div class="usdr-stat is-accent"><span class="usdr-stat-label">Matches</span><strong>' + data.count + '</strong></div>' +
            '<div class="usdr-stat"><span class="usdr-stat-label">Brand</span><strong>' + brand + '</strong></div>' +
            '<div class="usdr-stat"><span class="usdr-stat-label">Language</span><strong>' + language + '</strong></div>' +
            '<div class="usdr-stat"><span class="usdr-stat-label">Sheet slugs</span><strong>' + sheetSlugs + '</strong></div>' +
            '</div>' +
            '<div class="usdr-domain-preview">' +
            '<span class="usdr-domain-preview-label">Domain swap</span>' +
            '<code>' + oldDomain + '</code>' +
            '<span class="usdr-domain-preview-arrow">→</span>' +
            '<code>' + newDomain + '</code>' +
            '</div>'
        );

        $section.removeClass('is-hidden');
    }

    function getSelectedLinkIds() {
        const ids = [];
        $('.usdr-row-check:checked').each(function () {
            const id = parseInt($(this).val(), 10);
            if (id > 0) {
                ids.push(id);
            }
        });
        return ids;
    }

    function getTotalRowCount() {
        return $('.usdr-row-check').length;
    }

    function updateSelectAllState() {
        const $selectAll = $('#usdr-select-all');
        if (!$selectAll.length) {
            return;
        }

        const total = getTotalRowCount();
        const selected = getSelectedLinkIds().length;

        if (total === 0) {
            $selectAll.prop({ checked: false, indeterminate: false });
            return;
        }

        $selectAll.prop('checked', selected === total);
        $selectAll.prop('indeterminate', selected > 0 && selected < total);
    }

    function updateRowSelectionStyles() {
        $('.usdr-row-check').each(function () {
            $(this).closest('tr').toggleClass('is-unselected', !$(this).prop('checked'));
        });
    }

    function updateSelectionUI() {
        const total = getTotalRowCount();
        const selected = getSelectedLinkIds().length;
        const $badge = $('#usdr-selection-badge');

        if ($badge.length) {
            $badge.text(USDR.i18n.selectedCount.replace('%1$d', selected).replace('%2$d', total));
        }

        $('#usdr-replace-btn').prop('disabled', selected === 0);
        updateSelectAllState();
        updateRowSelectionStyles();
    }

    function bindSelectionEvents() {
        const $results = $('#usdr-results');
        let dragSelect = null;

        function endDragSelect() {
            dragSelect = null;
            $('body').removeClass('usdr-drag-selecting');
            $('.usdr-table tbody tr').removeClass('is-drag-hover');
        }

        function activateDragSelect() {
            if (!dragSelect || dragSelect.active) {
                return;
            }

            dragSelect.active = true;
            dragSelect.value = !dragSelect.initialChecked;
            $('body').addClass('usdr-drag-selecting');
            applyDragToRow(dragSelect.startRow);
            dragSelect.startRow.addClass('is-drag-hover');
        }

        function applyDragToRow($row) {
            if (!dragSelect || !dragSelect.active || !$row.length) {
                return;
            }

            $row.find('.usdr-row-check').prop('checked', dragSelect.value);
        }

        $results.off('change.usdrSelect', '#usdr-select-all');
        $results.off('change.usdrSelect', '.usdr-row-check');
        $results.off('mousedown.usdrDrag', '.usdr-table tbody tr');
        $results.off('mouseenter.usdrDrag', '.usdr-table tbody tr');
        $results.off('click.usdrDrag', '.usdr-row-check');
        $(document).off('mouseup.usdrDrag mousemove.usdrDrag');

        $results.on('change.usdrSelect', '#usdr-select-all', function () {
            const checked = $(this).prop('checked');
            $('.usdr-row-check').prop('checked', checked);
            updateSelectionUI();
        });

        $results.on('change.usdrSelect', '.usdr-row-check', function () {
            updateSelectionUI();
        });

        $results.on('click.usdrDrag', '.usdr-row-check', function (e) {
            e.preventDefault();
        });

        $results.on('mousedown.usdrDrag', '.usdr-table tbody tr', function (e) {
            if (e.which !== 1 || $(e.target).closest('#usdr-select-all').length) {
                return;
            }

            e.preventDefault();

            const $row = $(this);
            const $checkbox = $row.find('.usdr-row-check');

            dragSelect = {
                startRow: $row,
                startX: e.clientX,
                startY: e.clientY,
                initialChecked: $checkbox.prop('checked'),
                active: false,
                value: null,
            };
        });

        $results.on('mouseenter.usdrDrag', '.usdr-table tbody tr', function () {
            if (!dragSelect) {
                return;
            }

            const $row = $(this);

            if (!dragSelect.active && !$row.is(dragSelect.startRow)) {
                activateDragSelect();
            }

            if (!dragSelect.active) {
                return;
            }

            if ($row.hasClass('is-drag-hover')) {
                return;
            }

            $('.usdr-table tbody tr').removeClass('is-drag-hover');
            applyDragToRow($row);
            $row.addClass('is-drag-hover');
            updateSelectionUI();
        });

        $(document).on('mousemove.usdrDrag', function (e) {
            if (!dragSelect) {
                return;
            }

            const dx = Math.abs(e.clientX - dragSelect.startX);
            const dy = Math.abs(e.clientY - dragSelect.startY);

            if (!dragSelect.active && (dx > 4 || dy > 4)) {
                activateDragSelect();
            }

            if (!dragSelect.active) {
                return;
            }

            const target = document.elementFromPoint(e.clientX, e.clientY);
            if (!target) {
                return;
            }

            const $row = $(target).closest('.usdr-table tbody tr');
            if (!$row.length || !$row.closest('#usdr-results').length) {
                return;
            }

            if ($row.hasClass('is-drag-hover')) {
                return;
            }

            $('.usdr-table tbody tr').removeClass('is-drag-hover');
            applyDragToRow($row);
            $row.addClass('is-drag-hover');
            updateSelectionUI();
        });

        $(document).on('mouseup.usdrDrag', function () {
            if (!dragSelect) {
                return;
            }

            if (!dragSelect.active) {
                dragSelect.startRow
                    .find('.usdr-row-check')
                    .prop('checked', !dragSelect.initialChecked);
                updateSelectionUI();
            }

            endDragSelect();
        });
    }

    function renderPreview(preview) {
        const $results = $('#usdr-results');
        $results.empty();

        if (!preview || !preview.length) {
            $results.html(
                '<div class="usdr-empty-state">' +
                '<strong>No matching links</strong>' +
                'Try a different domain or verify the selected brand and language.' +
                '</div>'
            );
            return;
        }

        let html = '<div class="usdr-results-head">';
        html += '<h3>Matching links</h3>';
        html += '<span class="usdr-count-badge" id="usdr-selection-badge">' +
            USDR.i18n.selectedCount.replace('%1$d', preview.length).replace('%2$d', preview.length) +
            '</span>';
        html += '</div>';
        html += '<div class="usdr-results-table-wrap"><table class="usdr-table"><thead><tr>';
        html += '<th class="usdr-col-check"><input type="checkbox" id="usdr-select-all" checked aria-label="Select all links" /></th>';
        html += '<th>ID</th><th>Title</th><th>Slug</th><th>Old Target URL</th><th>New Target URL</th>';
        html += '</tr></thead><tbody>';

        preview.forEach(function (row) {
            html += '<tr>';
            html += '<td class="usdr-col-check"><input type="checkbox" class="usdr-row-check" value="' + row.id + '" checked aria-label="Select link ' + row.id + '" /></td>';
            html += '<td>' + row.id + '</td>';
            html += '<td>' + $('<div>').text(row.name || '').html() + '</td>';
            html += '<td><code>' + $('<div>').text(row.slug || '').html() + '</code></td>';
            html += '<td><code>' + $('<div>').text(row.old_url || '').html() + '</code></td>';
            html += '<td><code>' + $('<div>').text(row.new_url || '').html() + '</code></td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        $results.html(html);
        bindSelectionEvents();
        updateSelectionUI();
    }

    function fillBrandSelect(brands, selected) {
        const $brand = $('#usdr-brand');
        const current = selected || $brand.val() || '';
        $brand.empty();
        $brand.append($('<option>', { value: '', text: USDR.i18n.selectBrand }));

        (brands || []).forEach(function (item) {
            const name = item.name || item;
            $brand.append($('<option>', { value: name, text: name }));
        });

        if (current) {
            $brand.val(current);
        }

        $brand.prop('disabled', false);
        return $brand.val();
    }

    function resetLanguageSelect(placeholder, disabled) {
        const $language = $('#usdr-language');
        $language.empty();
        $language.append($('<option>', { value: '', text: placeholder || USDR.i18n.selectLanguage }));
        $language.prop('disabled', !!disabled);
        $('#usdr-replace-btn').prop('disabled', true);
    }

    function fillLanguageSelect(languages) {
        const $language = $('#usdr-language');
        $language.empty();

        if (!languages || !languages.length) {
            resetLanguageSelect(USDR.i18n.noLanguages, true);
            return;
        }

        $language.append($('<option>', { value: '', text: USDR.i18n.selectLanguage }));
        languages.forEach(function (item) {
            const code = item.code || item;
            const count = item.slug_count ? ' (' + item.slug_count + ' slugs)' : '';
            $language.append($('<option>', { value: code, text: code + count }));
        });
        $language.prop('disabled', false);
    }

    function loadLanguages(brand) {
        resetLanguageSelect(USDR.i18n.loadingLanguages, true);

        if (!brand) {
            resetLanguageSelect('Select a brand first', true);
            return $.Deferred().resolve().promise();
        }

        return $.post(USDR.ajaxUrl, {
            action: 'usdr_get_languages',
            nonce: USDR.nonce,
            brand: brand,
        }).done(function (response) {
            if (!response || !response.success) {
                const message = (response && response.data && response.data.message) || USDR.i18n.sheetFailed;
                resetLanguageSelect(message, true);
                setStatus(message, 'error');
                return;
            }

            fillLanguageSelect(response.data.languages);
        }).fail(function (xhr) {
            const message = parseAjaxError(xhr, USDR.i18n.sheetFailed);
            resetLanguageSelect(message, true);
            setStatus(message, 'error', xhr && xhr.responseText ? xhr.responseText : '');
        });
    }

    function loadBrands(forceRefresh) {
        const $brand = $('#usdr-brand');
        const $refresh = $('#usdr-refresh-sheet');
        const selected = $brand.val();

        $brand.prop('disabled', true);
        $refresh.prop('disabled', true);
        resetLanguageSelect(USDR.i18n.loadingLanguages, true);
        $brand.empty().append($('<option>', {
            value: '',
            text: forceRefresh ? USDR.i18n.refreshing : USDR.i18n.loadingBrands,
        }));

        return $.post(USDR.ajaxUrl, {
            action: forceRefresh ? 'usdr_refresh_sheet' : 'usdr_get_brands',
            nonce: USDR.nonce,
        }).done(function (response) {
            if (!response || !response.success) {
                const message = (response && response.data && response.data.message) || USDR.i18n.sheetFailed;
                $brand.empty().append($('<option>', { value: '', text: message }));
                setStatus(message, 'error');
                return;
            }

            const nextBrand = fillBrandSelect(response.data.brands, selected);
            if (forceRefresh && response.data.message) {
                setStatus(response.data.message, 'success');
            }

            if (nextBrand) {
                loadLanguages(nextBrand);
            } else {
                resetLanguageSelect('Select a brand first', true);
            }
        }).fail(function (xhr) {
            const message = parseAjaxError(xhr, USDR.i18n.sheetFailed);
            $brand.empty().append($('<option>', { value: '', text: message }));
            setStatus(message, 'error', xhr && xhr.responseText ? xhr.responseText : '');
        }).always(function () {
            $refresh.prop('disabled', false);
        });
    }

    function runReplace(linkIds) {
        return $.ajax({
            url: USDR.ajaxUrl,
            method: 'POST',
            timeout: 300000,
            traditional: true,
            data: formPayload({
                action: 'usdr_replace_links',
                link_ids: linkIds || [],
            }),
        }).then(function (response) {
            if (!response || !response.success) {
                const message = (response && response.data && response.data.message) || 'Replace failed.';
                const err = new Error(message);
                err.response = response;
                throw err;
            }

            return response.data;
        });
    }

    function boot() {
        if (typeof window.USDR === 'undefined') {
            setStatus(
                'Admin script failed to load. Please hard-refresh this page (Ctrl+F5 / Cmd+Shift+R) or re-upload the plugin assets folder.',
                'error'
            );
            return;
        }

        if (!isReady()) {
            setStatus(USDR.i18n.notReady, 'error', USDR.diagnostics && USDR.diagnostics.error ? USDR.diagnostics.error : '');
        }

        loadBrands(false);

        $('#usdr-brand').on('change', function () {
            $('#usdr-summary').empty();
            $('#usdr-results').empty();
            $('#usdr-results-section').addClass('is-hidden');
            $('#usdr-replace-btn').prop('disabled', true);
            loadLanguages($(this).val());
        });

        $('#usdr-language').on('change', function () {
            $('#usdr-replace-btn').prop('disabled', true);
        });

        $('#usdr-refresh-sheet').on('click', function () {
            $('#usdr-summary').empty();
            $('#usdr-results').empty();
            $('#usdr-results-section').addClass('is-hidden');
            $('#usdr-replace-btn').prop('disabled', true);
            setStatus(USDR.i18n.refreshing, 'info');
            loadBrands(true);
        });

        $('#usdr-scan-btn').on('click', function () {
            if (!isReady()) {
                setStatus(USDR.i18n.notReady, 'error', USDR.diagnostics.error || '');
                return;
            }

            if (!isFormValid()) {
                setStatus(USDR.i18n.invalid, 'error');
                return;
            }

            const $btn = $(this);
            $btn.prop('disabled', true);
            setStatus(USDR.i18n.scanning, 'info');
            $('#usdr-replace-btn').prop('disabled', true);
            $('#usdr-summary').empty();
            $('#usdr-results').empty();
            $('#usdr-results-section').addClass('is-hidden');

            $.post(USDR.ajaxUrl, formPayload({ action: 'usdr_scan_links' }))
                .done(function (response) {
                    if (!response || !response.success) {
                        const message = (response && response.data && response.data.message) || 'Scan failed.';
                        const details = response && response.data && response.data.diagnostics
                            ? JSON.stringify(response.data.diagnostics, null, 2)
                            : '';
                        setStatus(message, 'error', details);
                        return;
                    }

                    const data = response.data;
                    renderSummary(data);
                    renderPreview(data.preview);

                    if (data.count > 0) {
                        $('#usdr-replace-btn').prop('disabled', false);
                        setStatus('Found ' + data.count + ' matching link(s) for ' + data.brand + ' / ' + data.language + '. Uncheck any row to exclude it, then run replace.', 'success');
                    } else {
                        setStatus(USDR.i18n.noMatches, 'error');
                    }
                })
                .fail(function (xhr) {
                    setStatus(parseAjaxError(xhr, USDR.i18n.ajaxFailed), 'error', xhr && xhr.responseText ? xhr.responseText : '');
                })
                .always(function () {
                    $btn.prop('disabled', !isReady());
                });
        });

        $('#usdr-replace-btn').on('click', function () {
            if (!isReady()) {
                setStatus(USDR.i18n.notReady, 'error', USDR.diagnostics.error || '');
                return;
            }

            if (!isFormValid()) {
                setStatus(USDR.i18n.invalid, 'error');
                return;
            }

            const selectedIds = getSelectedLinkIds();
            if (!selectedIds.length) {
                setStatus(USDR.i18n.noneSelected, 'error');
                return;
            }

            const $scanBtn = $('#usdr-scan-btn');
            const $replaceBtn = $(this);
            const confirmMessage = USDR.i18n.confirm.replace('%d', selectedIds.length);

            confirmDialog(USDR.i18n.confirmTitle, confirmMessage).then(function (ok) {
                if (!ok) {
                    return;
                }

                $scanBtn.prop('disabled', true);
                $replaceBtn.prop('disabled', true);
                setStatus(USDR.i18n.replacing, 'info');

                runReplace(selectedIds)
                    .then(function (data) {
                        setStatus(
                            USDR.i18n.done + ' Updated: ' + (data.updated || 0) + ' / ' + (data.total_matches || 0) + ', Skipped: ' + (data.skipped || 0) + '.',
                            'success'
                        );
                        return $.post(USDR.ajaxUrl, formPayload({ action: 'usdr_scan_links' }));
                    })
                    .done(function (response) {
                        if (response && response.success) {
                            renderSummary(response.data);
                            renderPreview(response.data.preview);
                            if (!response.data.count) {
                                $('#usdr-replace-btn').prop('disabled', true);
                            } else {
                                updateSelectionUI();
                            }
                        }
                    })
                    .fail(function (xhr) {
                        setStatus(parseAjaxError(xhr, 'Replace failed.'), 'error', xhr && xhr.responseText ? xhr.responseText : '');
                    })
                    .always(function () {
                        $scanBtn.prop('disabled', !isReady());
                    });
            });
        });
    }

    $(boot);
})(jQuery);
