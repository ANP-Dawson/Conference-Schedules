// SPDX-License-Identifier: Apache-2.0
//
// Conference Schedules — UCP widget client. Auto-loaded by UCP from
// /var/www/html/ucp/modules/Conferenceschedules/assets/js/global.js (the
// symlink the framework creates from <admin module>/ucp/).
//
// IMPORTANT: UCP loads widgets via AJAX after the page renders, so any event
// handlers bound directly to the widget DOM at page-ready time would miss
// elements that haven't been injected yet. We use document-level delegation
// throughout to dodge the timing trap.

(function ($) {
    'use strict';

    var DOW_REVERSE = { 0: 'sun', 1: 'mon', 2: 'tue', 3: 'wed', 4: 'thu', 5: 'fri', 6: 'sat' };
    var debug = function () {
        if (window.console && window.console.log) {
            window.console.log.apply(window.console, ['[cs]'].concat([].slice.call(arguments)));
        }
    };

    // The UCP page reloads when widgets are added/removed, so we don't need
    // a MutationObserver — but we do need delegated handlers so they survive
    // widget AJAX re-renders within the same page.

    function ajaxUrl(command, extra) {
        var qs = $.param($.extend({ module: 'conferenceschedules', command: command }, extra || {}));
        return 'ajax.php?' + qs;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function fmtUtcInTz(utc, tz) {
        if (!utc) { return '<span class="text-muted">—</span>'; }
        try {
            var d = new Date(utc.replace(' ', 'T') + 'Z');
            return d.toLocaleString(undefined, {
                timeZone:  tz || 'UTC',
                weekday:   'short', month: 'short', day: 'numeric',
                hour:      '2-digit', minute: '2-digit', timeZoneName: 'short'
            });
        } catch (e) {
            return escapeHtml(utc) + ' UTC';
        }
    }

    var STATUS_LABEL = { success: 'success', partial: 'warning', failed: 'danger', skipped: 'default' };

    function rowHtml(job) {
        var enabled = job.enabled == 1
            ? '<span class="label label-success">Enabled</span>'
            : '<span class="label label-default">Disabled</span>';
        var status = job.last_status
            ? '<span class="label label-' + (STATUS_LABEL[job.last_status] || 'default') + '">'
                + escapeHtml(job.last_status) + '</span>'
            : '<span class="text-muted">—</span>';
        var conf = escapeHtml(job.conference_exten || '');
        if (job.conference_description) {
            conf += '<br><small class="text-muted">' + escapeHtml(job.conference_description) + '</small>';
        }
        var name = '<strong>' + escapeHtml(job.name) + '</strong>';
        if (job.description) {
            name += '<br><small class="text-muted">' + escapeHtml(job.description) + '</small>';
        }
        return '<tr data-job-id="' + job.id + '">'
            + '<td>' + name + '</td>'
            + '<td>' + conf + '</td>'
            + '<td>' + fmtUtcInTz(job.next_fire_utc, job.timezone) + '</td>'
            + '<td>' + fmtUtcInTz(job.last_fire_utc, job.timezone) + '</td>'
            + '<td>' + status + '</td>'
            + '<td>' + enabled + '</td>'
            + '<td class="text-right">'
                + '<button type="button" class="btn btn-sm btn-default cs-btn-edit" title="Edit"><i class="fa fa-pencil"></i></button> '
                + '<button type="button" class="btn btn-sm btn-warning cs-btn-fire" title="Fire Now"><i class="fa fa-play"></i></button> '
                + '<button type="button" class="btn btn-sm btn-danger cs-btn-delete" title="Delete"><i class="fa fa-trash"></i></button>'
            + '</td>'
            + '</tr>';
    }

    // ---- modal helpers -----------------------------------------------------

    // Bootstrap modals work most reliably when attached directly to <body>.
    // If the widget renders the modal inside a card/iframe-ish container,
    // pull it out the first time we need it.
    function getModal() {
        var $m = $('#cs-modal');
        if (!$m.length) {
            debug('cs-modal not found in DOM');
            return $();
        }
        if ($m.parent('body').length === 0) {
            $m.detach().appendTo('body');
        }
        return $m;
    }

    function getForm() { return $('#cs-form'); }

    // ---- list / history refresh -------------------------------------------

    function refreshJobs() {
        return $.getJSON(ajaxUrl('list')).then(function (resp) {
            if (!resp || resp.status !== true) { return; }
            var $tbody = $('#cs-jobs-table tbody');
            $tbody.empty();
            if (!resp.jobs.length) {
                $tbody.append(
                    '<tr class="cs-empty"><td colspan="7" class="text-center text-muted">'
                    + 'You have no schedules yet. Click "Add Schedule" to create one.</td></tr>'
                );
                return;
            }
            resp.jobs.forEach(function (j) { $tbody.append(rowHtml(j)); });
        });
    }

    function refreshHistory() {
        return $.getJSON(ajaxUrl('history')).then(function (resp) {
            if (!resp || resp.status !== true) { return; }
            var $tbody = $('.cs-history-rows');
            $tbody.empty();
            if (!resp.history.length) {
                $tbody.append('<tr><td colspan="5" class="text-center text-muted">No history yet.</td></tr>');
                return;
            }
            resp.history.forEach(function (h) {
                var legs;
                try { legs = JSON.parse(h.participants_json || '[]'); } catch (e) { legs = []; }
                var ok = legs.filter(function (l) { return l.response === 'Success'; }).length;
                var status = '<span class="label label-' + (STATUS_LABEL[h.status] || 'default') + '">'
                    + escapeHtml(h.status) + '</span>';
                $tbody.append(
                    '<tr>'
                    + '<td>' + escapeHtml(h.job_name || ('#' + h.job_id)) + '</td>'
                    + '<td>' + escapeHtml(h.fired_at_utc) + ' UTC</td>'
                    + '<td>' + status + '</td>'
                    + '<td>' + ok + ' / ' + legs.length + '</td>'
                    + '<td>' + (h.error_text
                        ? '<small class="text-danger">' + escapeHtml(h.error_text) + '</small>'
                        : '<span class="text-muted">—</span>') + '</td>'
                    + '</tr>'
                );
            });
        });
    }

    // ---- modal form fill --------------------------------------------------

    function resetForm() {
        var $f = getForm();
        if (!$f.length) { return; }
        $f[0].reset();
        $f.find('input[name="id"]').val('');
        $f.find('.cs-participants tbody').empty();
        $f.find('.cs-fire-preview').html(
            '<p class="text-muted"><small>Pick a frequency to see the next 5 fire times.</small></p>'
        );
        // Bootstrap 4 tab classes: tab-pane uses "show active", nav-link uses "active".
        $f.find('.cs-form-tabs .tab-pane').removeClass('show active');
        $f.find('#cs-tab-general').addClass('show active');
        $f.find('ul.nav-tabs .nav-link').removeClass('active');
        $f.find('ul.nav-tabs .nav-link').first().addClass('active');
        $f.find('select.cs-sched-freq').val('weekly');
        applyScheduleSections('weekly');
        $('.cs-form-error').hide().text('');
        $('.cs-modal-delete').hide();
        $('.cs-modal-title').text('New Schedule');
    }

    function fillForm(job) {
        var $f = getForm();
        $f.find('input[name="id"]').val(job.id);
        $f.find('input[name="name"]').val(job.name || '');
        $f.find('textarea[name="description"]').val(job.description || '');
        $f.find('select[name="conference_exten"]').val(job.conference_exten || '');
        $f.find('select[name="timezone"]').val(job.timezone || 'UTC');
        $f.find('input[name="enabled"]').prop('checked', job.enabled == 1);

        var sched = (job.schedules && job.schedules[0]) || null;
        var freq = 'weekly';
        if (sched) {
            if (sched.type === 'oneoff' && sched.start_dt) {
                freq = 'oneoff';
                $f.find('input[name="schedule[start_dt]"]').val(sched.start_dt.replace(' ', 'T').slice(0, 16));
            } else if (sched.type === 'cron') {
                freq = 'custom_cron';
                $f.find('input[name="schedule[cron_expr]"]').val(sched.cron_expr);
            } else if (sched.cron_expr) {
                var expr = sched.cron_expr;
                var m;
                if ((m = expr.match(/^@nth:([1-4L]):(\d):(\d{2}):(\d{2})$/))) {
                    freq = 'monthly_ordinal';
                    $f.find('select[name="schedule[ordinal]"]').val(m[1]);
                    $f.find('select[name="schedule[dow]"]').val(DOW_REVERSE[m[2]]);
                    $f.find('[data-types="monthly_ordinal"] input[type="time"]').val(m[3] + ':' + m[4]);
                } else if ((m = expr.match(/^(\d+)\s+(\d+)\s+(\d+)\s+1,4,7,10\s+\*$/))) {
                    freq = 'quarterly_dom';
                    $f.find('input[name="schedule[dom]"]').val(parseInt(m[3], 10));
                    $f.find('[data-types*="dom"] input[type="time"]').val(pad2(m[2]) + ':' + pad2(m[1]));
                } else if ((m = expr.match(/^(\d+)\s+(\d+)\s+(\d+)\s+\*\s+\*$/))) {
                    freq = 'monthly_dom';
                    $f.find('input[name="schedule[dom]"]').val(parseInt(m[3], 10));
                    $f.find('[data-types*="dom"] input[type="time"]').val(pad2(m[2]) + ':' + pad2(m[1]));
                } else if ((m = expr.match(/^(\d+)\s+(\d+)\s+\*\s+\*\s+([\d,]+)$/))) {
                    freq = 'weekly';
                    $f.find('[data-types="weekly"] input[type="time"]').val(pad2(m[2]) + ':' + pad2(m[1]));
                    $f.find('input[name="schedule[dows][]"]').prop('checked', false);
                    m[3].split(',').forEach(function (d) {
                        var name = DOW_REVERSE[parseInt(d, 10)];
                        if (name) {
                            $f.find('input[name="schedule[dows][]"][value="' + name + '"]').prop('checked', true);
                        }
                    });
                } else if ((m = expr.match(/^(\d+)\s+(\d+)\s+\*\s+\*\s+\*$/))) {
                    freq = 'daily';
                    $f.find('[data-types="daily"] input[type="time"]').val(pad2(m[2]) + ':' + pad2(m[1]));
                } else {
                    freq = 'custom_cron';
                    $f.find('input[name="schedule[cron_expr]"]').val(expr);
                }
            }
        }
        $f.find('select.cs-sched-freq').val(freq);
        applyScheduleSections(freq);

        var opt = job.options || {};
        $f.find('input[name="options[caller_id_name]"]').val(opt.caller_id_name || '');
        $f.find('input[name="options[caller_id_num]"]').val(opt.caller_id_num || '');
        $f.find('input[name="options[wait_time_sec]"]').val(opt.wait_time_sec || 45);
        $f.find('select[name="options[concurrency_policy]"]').val(opt.concurrency_policy || 'skip_if_active');

        var $tbody = $f.find('.cs-participants tbody').empty();
        (job.participants || []).forEach(function (p) {
            $tbody.append(buildParticipantRow(nextRowIndex(), p));
        });

        $('.cs-modal-delete').show();
        $('.cs-modal-title').text('Edit Schedule: ' + job.name);
    }

    function pad2(s) { return ('00' + s).slice(-2); }

    function nextRowIndex() {
        var max = -1;
        $('#cs-form .cs-participants tbody tr').each(function () {
            $(this).find('input,select').each(function () {
                var n = $(this).attr('name') || '';
                var m = n.match(/^participants\[(\d+)\]/);
                if (m) { max = Math.max(max, parseInt(m[1], 10)); }
            });
        });
        return max + 1;
    }

    function buildParticipantRow(idx, p) {
        var tplHtml = $('#cs-participant-template').html();
        if (!tplHtml) { return $(); }
        var html = tplHtml.replace(/__I__/g, idx);
        var $row = $($.parseHTML(html));
        if (p) {
            $row.find('select.cs-kind').val(p.kind || 'extension');
            if ((p.kind || 'extension') === 'extension') {
                $row.find('select.cs-ext-picker').val(p.value || '').show().prop('disabled', false);
                $row.find('input.cs-ext-text').hide().val(p.value || '');
            } else {
                $row.find('select.cs-ext-picker').hide().prop('disabled', true);
                $row.find('input.cs-ext-text').show().val(p.value || '');
            }
            $row.find('input[name$="[display_name]"]').val(p.display_name || '');
            $row.find('input.cs-sort-order').val(p.sort_order || 0);
        }
        return $row;
    }

    function applyScheduleSections(freq) {
        $('#cs-form .cs-sched-section').each(function () {
            var types = String($(this).data('types') || '').split(/\s+/);
            var visible = types.indexOf(freq) !== -1;
            $(this).toggle(visible);
            $(this).find('input,select,textarea').prop('disabled', !visible);
        });
    }

    // ---- preview AJAX -----------------------------------------------------

    var previewTimer;
    function refreshPreview() {
        var $wrap = $('#cs-form .cs-fire-preview');
        var freq  = $('#cs-form select.cs-sched-freq').val();
        if (!freq) { return; }
        $wrap.html('<p class="text-muted"><small><i class="fa fa-spinner fa-spin"></i> Computing…</small></p>');

        var params = $('#cs-tab-schedule')
            .find('input,select,textarea').serializeArray();
        params.push({ name: 'tz', value: $('#cs-form select[name="timezone"]').val() || 'UTC' });

        $.getJSON(ajaxUrl('preview') + '&' + $.param(params)).done(function (resp) {
            if (!resp || resp.status !== true) {
                $wrap.html('<p class="text-danger">' + escapeHtml((resp && resp.message) || 'Preview failed.') + '</p>');
                return;
            }
            if (!resp.times || !resp.times.length) {
                $wrap.html('<p class="text-muted"><small>No upcoming fire times.</small></p>');
                return;
            }
            var html = '<p><strong>Next ' + resp.times.length + ' fire times</strong>'
                + ' (in ' + escapeHtml($('#cs-form select[name="timezone"]').val() || 'UTC') + '):</p><ul>';
            resp.times.forEach(function (t) {
                html += '<li><code>' + escapeHtml(t) + '</code></li>';
            });
            html += '</ul>';
            $wrap.html(html);
        }).fail(function () {
            $wrap.html('<p class="text-danger">Preview request failed.</p>');
        });
    }

    function debouncePreview() {
        if (previewTimer) { clearTimeout(previewTimer); }
        previewTimer = setTimeout(refreshPreview, 250);
    }

    // ---- save / delete / fire ---------------------------------------------

    function saveSchedule() {
        var $f = getForm();
        var data = $f.find('input:not(:disabled),select:not(:disabled),textarea:not(:disabled)').serialize();
        if (!$f.find('input[name="enabled"]').is(':checked')) { data += '&enabled=0'; }
        $('.cs-form-error').hide();
        $('.cs-modal-save').prop('disabled', true);
        $.post(ajaxUrl('save'), data, null, 'json').done(function (resp) {
            if (!resp || resp.status !== true) {
                $('.cs-form-error').text((resp && resp.message) || 'Save failed.').show();
                return;
            }
            getModal().modal('hide');
            refreshJobs();
        }).fail(function () {
            $('.cs-form-error').text('Save request failed.').show();
        }).always(function () {
            $('.cs-modal-save').prop('disabled', false);
        });
    }

    function deleteSchedule(id) {
        if (!confirm('Delete this schedule? This cannot be undone.')) { return; }
        $.post(ajaxUrl('delete'), { id: id }, null, 'json').done(function () {
            refreshJobs();
        });
    }

    function fireSchedule(id) {
        if (!confirm('Fire this schedule now? Phones will ring immediately.')) { return; }
        $.post(ajaxUrl('fire'), { id: id }, null, 'json').done(function () {
            refreshJobs();
            refreshHistory();
        });
    }

    function openEdit(id) {
        $.getJSON(ajaxUrl('get') + '&id=' + id).done(function (resp) {
            if (!resp || resp.status !== true) {
                alert((resp && resp.message) || 'Could not load schedule.');
                return;
            }
            resetForm();
            fillForm(resp.job);
            ensureSortable();
            getModal().modal('show');
        });
    }

    function openAdd() {
        debug('openAdd clicked');
        resetForm();
        try {
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (tz) { $('#cs-form select[name="timezone"]').val(tz); }
        } catch (e) { /* fallback to UTC */ }
        ensureSortable();
        getModal().modal('show');
    }

    function ensureSortable() {
        if ($.fn.sortable) {
            var $tbody = $('#cs-form .cs-participants tbody.cs-sortable');
            if ($tbody.length && !$tbody.data('ui-sortable') && !$tbody.data('sortable')) {
                $tbody.sortable({
                    handle: '.cs-drag-handle',
                    axis: 'y',
                    update: function () {
                        $(this).find('tr').each(function (i) {
                            $(this).find('.cs-sort-order').val(i);
                        });
                    }
                });
            }
        }
    }

    // ---- ALL handlers via document delegation -----------------------------
    // This survives any AJAX re-render of either the widget body or the modal.

    $(document)
        .on('click', '#cs-widget .cs-btn-add', openAdd)
        .on('click', '#cs-widget .cs-btn-refresh', function () { refreshJobs(); refreshHistory(); })
        .on('click', '#cs-widget .cs-btn-edit', function () { openEdit($(this).closest('tr').data('job-id')); })
        .on('click', '#cs-widget .cs-btn-fire', function () { fireSchedule($(this).closest('tr').data('job-id')); })
        .on('click', '#cs-widget .cs-btn-delete', function () { deleteSchedule($(this).closest('tr').data('job-id')); })
        .on('shown.bs.tab', 'a[href="#cs-pane-history"]', refreshHistory)
        // Modal
        .on('click', '.cs-modal-save', saveSchedule)
        .on('click', '.cs-modal-delete', function () {
            var id = $('#cs-form input[name="id"]').val();
            if (id) { deleteSchedule(id); getModal().modal('hide'); }
        })
        // Schedule tab
        .on('change', '#cs-form select.cs-sched-freq', function () {
            applyScheduleSections($(this).val());
            refreshPreview();
        })
        .on('change input', '#cs-tab-schedule input, #cs-tab-schedule select', debouncePreview)
        .on('change', '#cs-form select[name="timezone"]', debouncePreview)
        .on('shown.bs.tab', 'a[href="#cs-tab-schedule"]', refreshPreview)
        // Participants
        .on('click', '#cs-form .cs-add-participant', function () {
            $('#cs-form .cs-participants tbody').append(buildParticipantRow(nextRowIndex()));
        })
        .on('click', '#cs-form .cs-remove-row', function () { $(this).closest('tr').remove(); })
        .on('change', '#cs-form .cs-kind', function () {
            var $row = $(this).closest('tr');
            var kind = $(this).val();
            if (kind === 'extension') {
                $row.find('.cs-ext-picker').show().prop('disabled', false);
                $row.find('.cs-ext-text').hide().prop('disabled', false);
                var p = $row.find('.cs-ext-picker').val();
                if (p) { $row.find('.cs-ext-text').val(p); }
            } else {
                $row.find('.cs-ext-picker').hide().prop('disabled', true);
                $row.find('.cs-ext-text').show().prop('disabled', false);
            }
        })
        .on('change', '#cs-form .cs-ext-picker', function () {
            $(this).closest('tr').find('.cs-ext-text').val($(this).val());
        })
        // Re-init tooltips inside the freshly-shown modal
        .on('shown.bs.modal', '#cs-modal', function () {
            if ($.fn.tooltip) {
                $(this).find('[data-toggle="tooltip"]').tooltip();
            }
            ensureSortable();
        });

    debug('cs UCP handlers wired (document-level delegation)');

    // Canonical UCP module registration: UCP looks for <Modulename>C in the
    // global scope after the asset bundle loads. Even though we use document
    // delegation for all event handling, registering with UCPMC means UCP
    // calls our displayWidget() lifecycle hook on widget mount — useful for
    // re-initializing sortable / tooltips after re-renders.
    if (typeof window.UCPMC !== 'undefined') {
        window.ConferenceschedulesC = window.UCPMC.extend({
            init: function () {
                debug('ConferenceschedulesC.init');
            },
            displayWidget: function (widget_id, dashboard_id) {
                debug('displayWidget', widget_id, dashboard_id);
                // Document delegation handles clicks; this hook is for any
                // per-widget initialization that needs the freshly-injected DOM.
                if ($.fn.tooltip) {
                    $('[data-toggle="tooltip"]').tooltip();
                }
            },
            displaySimpleWidgetSettings: function (widget_id) {
                debug('displaySimpleWidgetSettings', widget_id);
            },
            displayWidgetSettings: function (widget_id) {
                debug('displayWidgetSettings', widget_id);
            }
        });
        debug('ConferenceschedulesC registered with UCPMC');
    } else {
        debug('UCPMC not defined — relying on document delegation only');
    }

})(window.jQuery);
