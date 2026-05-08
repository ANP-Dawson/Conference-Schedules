// SPDX-License-Identifier: Apache-2.0
//
// UI glue for the conferenceschedules form:
//   - participant rows: add / remove / renumber array indices
//   - schedule preview: AJAX call to compile cron + fetch next 5 fire times

(function ($) {
    'use strict';

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    // ---- Participants ------------------------------------------------------

    function nextRowIndex($table) {
        var max = -1;
        $table.find('tbody .cs-participant-row input, tbody .cs-participant-row select').each(function () {
            var name = $(this).attr('name') || '';
            var m = name.match(/^participants\[(\d+)\]/);
            if (m) {
                max = Math.max(max, parseInt(m[1], 10));
            }
        });
        return max + 1;
    }

    function wireParticipants() {
        var $table = $('#cs-participants-table');
        if (!$table.length) {
            return;
        }

        $('#cs-add-participant').on('click', function () {
            var tpl = $('#cs-participant-template').html();
            if (!tpl) {
                return;
            }
            var idx = nextRowIndex($table);
            var html = tpl.replace(/__I__/g, idx);
            $table.find('tbody').append(html);
        });

        $table.on('click', '.cs-remove-row', function () {
            $(this).closest('tr').remove();
        });
    }

    // ---- Schedule preview --------------------------------------------------

    var previewTimer = null;

    function refreshSchedulePreview() {
        var $wrap = $('#cs-cron-preview-wrap');
        if (!$wrap.length) {
            return;
        }

        var dows = $('input[name="quick_dows[]"]:checked').map(function () {
            return this.value;
        }).get();
        var time = ($('#cs-time').val() || '').trim();
        var tz = $('#cs-tz').val() || 'UTC';

        if (!dows.length || !time) {
            $wrap.html(
                '<p class="text-muted"><small>'
                + 'Pick at least one day and a time to see the next 5 fire times.'
                + '</small></p>'
            );
            return;
        }

        $wrap.html('<p class="text-muted"><small><i class="fa fa-spinner fa-spin"></i> Computing…</small></p>');

        $.get('ajax.php', {
            module: 'conferenceschedules',
            command: 'preview-quick-recurring',
            dows: dows,
            time: time,
            tz: tz
        }).done(function (resp) {
            // FreePBX may envelope the response — handle both `status:true` and
            // `{status:"success", data:{...}}` shapes.
            var data = resp;
            if (resp && resp.data && typeof resp.data === 'object') {
                data = resp.data;
            }

            var ok = data && (data.status === true || data.status === 'success' || (resp.status === true || resp.status === 'success'));
            if (!ok) {
                var msg = (data && data.message) || (resp && resp.message) || 'Preview failed.';
                $wrap.html('<p class="text-danger">' + escapeHtml(msg) + '</p>');
                return;
            }

            var cron = data.cron || resp.cron || '';
            var times = data.times || resp.times || [];

            var html = '<p><small class="text-muted">Compiled cron: <code>'
                + escapeHtml(cron) + '</code></small></p>';
            html += '<p><strong>Next 5 fire times</strong> (in ' + escapeHtml(tz) + '):</p>';
            html += '<ul>';
            for (var i = 0; i < times.length; i++) {
                html += '<li><code>' + escapeHtml(times[i]) + '</code></li>';
            }
            html += '</ul>';
            $wrap.html(html);
        }).fail(function (xhr) {
            $wrap.html(
                '<p class="text-danger">Preview request failed: '
                + escapeHtml(xhr.statusText || 'unknown error')
                + '</p>'
            );
        });
    }

    function wireSchedulePreview() {
        var $tab = $('#cs-tab-schedule');
        if (!$tab.length) {
            return;
        }

        var debounced = function () {
            if (previewTimer) {
                clearTimeout(previewTimer);
            }
            previewTimer = setTimeout(refreshSchedulePreview, 250);
        };

        $('input[name="quick_dows[]"], #cs-time, #cs-tz').on('change input', debounced);

        // Initial render — but only when the schedule tab is opened, to keep
        // the form snappy on first paint.
        $('a[href="#cs-tab-schedule"]').on('shown.bs.tab', refreshSchedulePreview);

        // If the tab is the active one on load (e.g. error redisplay), render now.
        if ($tab.hasClass('active')) {
            refreshSchedulePreview();
        }
    }

    $(function () {
        wireParticipants();
        wireSchedulePreview();
    });
})(window.jQuery);
