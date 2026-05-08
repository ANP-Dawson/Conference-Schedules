// SPDX-License-Identifier: Apache-2.0
//
// UI glue for the conferenceschedules form:
//   - Schedule tab: frequency dropdown shows/hides per-frequency sections.
//     Hidden inputs are *disabled* so they don't submit alongside the active
//     section's values — keeps the server-side parse unambiguous.
//   - Participants: drag-handle reorder via jQuery UI sortable; extension
//     dropdown ↔ text input switches based on selected kind.
//   - Options: Bootstrap tooltips on the help icons.
//   - AJAX preview of the next 5 fire times.

(function ($) {
    'use strict';

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    // ---- Schedule frequency show/hide --------------------------------------

    function applyScheduleFrequency(freq) {
        $('.cs-sched-section').each(function () {
            var types = String($(this).data('types') || '').split(/\s+/);
            var visible = types.indexOf(freq) !== -1;
            $(this).toggle(visible);
            // Disable hidden-section inputs so they don't pollute the POST.
            $(this).find('input, select, textarea').prop('disabled', !visible);
        });
    }

    function wireScheduleFrequency() {
        var $sel = $('#cs-sched-freq');
        if (!$sel.length) {
            return;
        }
        applyScheduleFrequency($sel.val());
        $sel.on('change', function () {
            applyScheduleFrequency($(this).val());
            refreshSchedulePreview();
        });
    }

    // ---- Schedule preview --------------------------------------------------

    var previewTimer = null;

    function refreshSchedulePreview() {
        var $wrap = $('#cs-fire-preview-wrap');
        if (!$wrap.length) {
            return;
        }

        var freq = $('#cs-sched-freq').val();
        if (!freq) {
            return;
        }

        $wrap.html(
            '<p class="text-muted"><small><i class="fa fa-spinner fa-spin"></i> '
            + 'Computing…</small></p>'
        );

        // Serialize only the (currently enabled) Schedule tab inputs, plus the
        // tz from General. jQuery's .serialize() skips disabled inputs.
        var params = $('#cs-tab-schedule')
            .find('input, select, textarea')
            .serializeArray();
        params.push({ name: 'tz', value: $('#cs-tz').val() || 'UTC' });
        params.push({ name: 'module', value: 'conferenceschedules' });
        params.push({ name: 'command', value: 'preview-schedule' });

        $.get('ajax.php', $.param(params)).done(function (resp) {
            var data = resp;
            if (resp && resp.data && typeof resp.data === 'object') {
                data = resp.data;
            }

            var ok = data && (
                data.status === true || data.status === 'success'
                || resp.status === true || resp.status === 'success'
            );
            if (!ok) {
                var msg = (data && data.message) || (resp && resp.message) || 'Preview failed.';
                $wrap.html('<p class="text-danger">' + escapeHtml(msg) + '</p>');
                return;
            }

            var times = data.times || resp.times || [];
            if (!times.length) {
                $wrap.html('<p class="text-muted"><small>No upcoming fire times.</small></p>');
                return;
            }

            var html = '<p><strong>Next ' + times.length + ' fire times</strong>'
                + ' (in ' + escapeHtml($('#cs-tz').val() || 'UTC') + '):</p>';
            html += '<ul style="font-family:monospace">';
            for (var i = 0; i < times.length; i++) {
                html += '<li>' + escapeHtml(times[i]) + '</li>';
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

        $tab.on('change input', 'input, select', debounced);
        $('#cs-tz').on('change', debounced);
        $('a[href="#cs-tab-schedule"]').on('shown.bs.tab', refreshSchedulePreview);
    }

    // ---- Participants ------------------------------------------------------

    function nextRowIndex($table) {
        var max = -1;
        $table.find('tbody .cs-participant-row').each(function () {
            $(this).find('input, select').each(function () {
                var name = $(this).attr('name') || '';
                var m = name.match(/^participants\[(\d+)\]/);
                if (m) {
                    max = Math.max(max, parseInt(m[1], 10));
                }
            });
        });
        return max + 1;
    }

    function renumberSortOrders($table) {
        $table.find('tbody .cs-participant-row').each(function (idx) {
            $(this).find('.cs-sort-order').val(idx);
        });
    }

    function applyParticipantKind($row) {
        var kind = $row.find('.cs-kind').val();
        if (kind === 'extension') {
            $row.find('.cs-ext-picker').show().prop('disabled', false);
            $row.find('.cs-ext-text').hide();
            // Sync hidden text input from picker so the post carries a value.
            var pickerVal = $row.find('.cs-ext-picker').val();
            if (pickerVal) {
                $row.find('.cs-ext-text').val(pickerVal);
            }
        } else {
            $row.find('.cs-ext-picker').hide().prop('disabled', true);
            $row.find('.cs-ext-text').show();
        }
    }

    function wireParticipants() {
        var $table = $('#cs-participants-table');
        if (!$table.length) {
            return;
        }

        // Initial state: apply kind visibility on each existing row.
        $table.find('.cs-participant-row').each(function () {
            applyParticipantKind($(this));
        });

        // Add row.
        $('#cs-add-participant').on('click', function () {
            var tpl = $('#cs-participant-template').html();
            if (!tpl) {
                return;
            }
            var idx = nextRowIndex($table);
            var html = tpl.replace(/__I__/g, idx);
            var $newRow = $(html);
            $table.find('tbody').append($newRow);
            applyParticipantKind($newRow);
            renumberSortOrders($table);
        });

        // Remove row.
        $table.on('click', '.cs-remove-row', function () {
            $(this).closest('tr').remove();
            renumberSortOrders($table);
        });

        // Toggle extension picker / external text input on kind change.
        $table.on('change', '.cs-kind', function () {
            applyParticipantKind($(this).closest('tr'));
        });

        // Sync extension dropdown selection into the hidden text input.
        $table.on('change', '.cs-ext-picker', function () {
            var $row = $(this).closest('tr');
            $row.find('.cs-ext-text').val($(this).val());
        });

        // Drag-handle reorder. jQuery UI sortable is loaded by FreePBX admin.
        if ($.fn.sortable) {
            $table.find('tbody.cs-sortable').sortable({
                handle: '.cs-drag-handle',
                axis: 'y',
                placeholder: 'cs-sortable-placeholder',
                update: function () {
                    renumberSortOrders($table);
                }
            });
        }
    }

    // ---- Tooltips ----------------------------------------------------------

    function wireTooltips() {
        if ($.fn.tooltip) {
            $('[data-toggle="tooltip"]').tooltip({ container: 'body' });
        }
    }

    $(function () {
        wireScheduleFrequency();
        wireSchedulePreview();
        wireParticipants();
        wireTooltips();
    });
})(window.jQuery);
