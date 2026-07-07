(function ($) {
    'use strict';

    function getDomains() {
        return {
            oldDomain: $('#usdr-old-domain').val().trim(),
            newDomain: $('#usdr-new-domain').val().trim(),
        };
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

    function renderSummary(data) {
        const $summary = $('#usdr-summary');
        if (!data || typeof data.count === 'undefined') {
            $summary.empty();
            return;
        }

        $summary.html(
            '<p><strong>' + data.count + '</strong> matching link(s) found for <code>' +
            $('<div>').text(data.old_domain).html() + '</code> → <code>' +
            $('<div>').text(data.new_domain).html() + '</code></p>'
        );
    }

    function renderPreview(preview) {
        const $results = $('#usdr-results');
        $results.empty();

        if (!preview || !preview.length) {
            return;
        }

        let html = '<h2>Preview</h2><table class="widefat striped"><thead><tr>';
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

        html += '</tbody></table>';
        $results.html(html);
    }

    function runReplace(domains, offset, totals) {
        totals = totals || { updated: 0, skipped: 0, processed: 0 };

        return $.post(USDR.ajaxUrl, {
            action: 'usdr_replace_links',
            nonce: USDR.nonce,
            old_domain: domains.oldDomain,
            new_domain: domains.newDomain,
            offset: offset,
        }).then(function (response) {
            if (!response || !response.success) {
                const message = (response && response.data && response.data.message) || 'Replace failed.';
                const err = new Error(message);
                err.response = response;
                throw err;
            }

            const data = response.data;
            totals.updated += data.updated || 0;
            totals.skipped += data.skipped || 0;
            totals.processed += data.processed || 0;

            setStatus(
                USDR.i18n.replacing + ' Updated: ' + totals.updated + ', Skipped: ' + totals.skipped,
                'info'
            );

            if (data.has_more) {
                return runReplace(domains, data.next_offset, totals);
            }

            return totals;
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

        $('#usdr-scan-btn').on('click', function () {
            if (!isReady()) {
                setStatus(USDR.i18n.notReady, 'error', USDR.diagnostics.error || '');
                return;
            }

            const domains = getDomains();
            if (!domains.oldDomain || !domains.newDomain) {
                setStatus(USDR.i18n.invalid, 'error');
                return;
            }

            const $btn = $(this);
            $btn.prop('disabled', true);
            setStatus(USDR.i18n.scanning, 'info');
            $('#usdr-replace-btn').prop('disabled', true);
            $('#usdr-summary').empty();
            $('#usdr-results').empty();

            $.post(USDR.ajaxUrl, {
                action: 'usdr_scan_links',
                nonce: USDR.nonce,
                old_domain: domains.oldDomain,
                new_domain: domains.newDomain,
            })
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
                        setStatus('Found ' + data.count + ' matching link(s). Review the preview, then run replace.', 'success');
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

            const domains = getDomains();
            if (!domains.oldDomain || !domains.newDomain) {
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

            runReplace(domains, 0)
                .then(function (totals) {
                    setStatus(
                        USDR.i18n.done + ' Updated: ' + totals.updated + ', Skipped: ' + totals.skipped + '.',
                        'success'
                    );
                    return $.post(USDR.ajaxUrl, {
                        action: 'usdr_scan_links',
                        nonce: USDR.nonce,
                        old_domain: domains.oldDomain,
                        new_domain: domains.newDomain,
                    });
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
