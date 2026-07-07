(function ($) {
    'use strict';

    function getDomains() {
        return {
            oldDomain: $('#usdr-old-domain').val().trim(),
            newDomain: $('#usdr-new-domain').val().trim(),
        };
    }

    function setStatus(message, type) {
        const $status = $('#usdr-status');
        $status.removeClass('is-info is-success is-error');
        if (type) {
            $status.addClass('is-' + type);
        }
        $status.text(message || '');
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
                throw new Error((response && response.data && response.data.message) || 'Replace failed.');
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

    $('#usdr-scan-btn').on('click', function () {
        const domains = getDomains();
        if (!domains.oldDomain || !domains.newDomain) {
            setStatus(USDR.i18n.invalid, 'error');
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true);
        setStatus(USDR.i18n.scanning, 'info');
        $('#usdr-replace-btn').prop('disabled', true);

        $.post(USDR.ajaxUrl, {
            action: 'usdr_scan_links',
            nonce: USDR.nonce,
            old_domain: domains.oldDomain,
            new_domain: domains.newDomain,
        })
            .done(function (response) {
                if (!response || !response.success) {
                    throw new Error((response && response.data && response.data.message) || 'Scan failed.');
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
                const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                    ? xhr.responseJSON.data.message
                    : 'Scan failed.';
                setStatus(message, 'error');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

    $('#usdr-replace-btn').on('click', function () {
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
                const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                    ? xhr.responseJSON.data.message
                    : 'Replace failed.';
                setStatus(message, 'error');
            })
            .always(function () {
                $scanBtn.prop('disabled', false);
            });
    });
})(jQuery);
