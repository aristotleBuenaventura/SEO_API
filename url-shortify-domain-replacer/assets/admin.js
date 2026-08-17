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
            html += '<p class="usdr-status-message">' + $('<div>').text(message).html() + '</p>';
        }
        if (details) {
            html += '<pre class="usdr-status-details">' + $('<div>').text(details).html() + '</pre>';
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

    function renderSummary(data) {
        const $summary = $('#usdr-summary');
        if (!data || typeof data.count === 'undefined') {
            $summary.empty();
            return;
        }

        const brand = $('<div>').text(data.brand || '').html();
        const language = $('<div>').text(data.language || '').html();
        const oldDomain = $('<div>').text(data.old_domain).html();
        const newDomain = $('<div>').text(data.new_domain).html();
        const sheetSlugs = typeof data.sheet_slugs !== 'undefined' ? data.sheet_slugs : 0;

        $summary.html(
            '<p><strong>' + data.count + '</strong> matching link(s) found for <code>' +
            oldDomain + '</code> → <code>' + newDomain + '</code></p>' +
            '<p>Filter: brand <code>' + brand + '</code>, language <code>' + language +
            '</code>, Google Sheet slugs <strong>' + sheetSlugs + '</strong>.</p>'
        );
    }

    function renderPreview(preview) {
        const $results = $('#usdr-results');
        $results.empty();

        if (!preview || !preview.length) {
            return;
        }

        let html = '<h2>Filtered Matching Links (' + preview.length + ')</h2>';
        html += '<div class="usdr-results-table-wrap"><table class="widefat striped"><thead><tr>';
        html += '<th>ID</th><th>Title</th><th>Slug</th><th>Old Target URL</th><th>New Target URL</th>';
        html += '</tr></thead><tbody>';

        preview.forEach(function (row) {
            html += '<tr>';
            html += '<td>' + row.id + '</td>';
            html += '<td>' + $('<div>').text(row.name || '').html() + '</td>';
            html += '<td><code>' + $('<div>').text(row.slug || '').html() + '</code></td>';
            html += '<td><code>' + $('<div>').text(row.old_url || '').html() + '</code></td>';
            html += '<td><code>' + $('<div>').text(row.new_url || '').html() + '</code></td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        $results.html(html);
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

    function runReplace() {
        return $.ajax({
            url: USDR.ajaxUrl,
            method: 'POST',
            timeout: 300000,
            data: formPayload({ action: 'usdr_replace_links' }),
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
            $('#usdr-replace-btn').prop('disabled', true);
            loadLanguages($(this).val());
        });

        $('#usdr-language').on('change', function () {
            $('#usdr-replace-btn').prop('disabled', true);
        });

        $('#usdr-refresh-sheet').on('click', function () {
            $('#usdr-summary').empty();
            $('#usdr-results').empty();
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
                        setStatus('Found ' + data.count + ' matching link(s) for ' + data.brand + ' / ' + data.language + '. Review the list below, then run replace.', 'success');
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

            if (!window.confirm(USDR.i18n.confirm)) {
                return;
            }

            const $scanBtn = $('#usdr-scan-btn');
            const $replaceBtn = $(this);
            $scanBtn.prop('disabled', true);
            $replaceBtn.prop('disabled', true);
            setStatus(USDR.i18n.replacing, 'info');

            runReplace()
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
    }

    $(boot);
})(jQuery);
