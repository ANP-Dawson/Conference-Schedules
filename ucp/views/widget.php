<?php

// SPDX-License-Identifier: Apache-2.0
//
// UCP widget body for Conference Schedules. Renders the user's schedule list,
// the add/edit modal (4 tabs identical to the admin form), and a small history
// pane. JS lives in ucp/assets/js/global.js.
//
// Receives via $this->load_view() argument extraction:
//   $jobs        - array<int,array> from listJobs($userId)
//   $conferences - array of ['exten' => ..., 'description' => ...]
//   $extensions  - array of ['extension' => ..., 'name' => ...]
//   $history     - recent history rows for this user
//   $userId      - current UCP user id

if (!defined('UCP_WEBROOT')) {
    if (!isset($jobs)) {
        // Defensive: when this view is accidentally hit directly.
        die('No direct script access allowed');
    }
}

$jobs        = $jobs        ?? [];
$conferences = $conferences ?? [];
$extensions  = $extensions  ?? [];
$history     = $history     ?? [];

$dowOptions = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
               'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
$ordOptions = ['1' => _('First'), '2' => _('Second'), '3' => _('Third'),
               '4' => _('Fourth'), 'L' => _('Last')];

$fmt = function (?string $utc, ?string $tz): string {
    if (empty($utc)) {
        return '<span class="text-muted">—</span>';
    }
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz ?: 'UTC'));
        return htmlspecialchars($dt->format('D M j, H:i T'));
    } catch (\Exception $e) {
        return htmlspecialchars($utc) . ' UTC';
    }
};

$statusClassMap = [
    'success' => 'success',
    'partial' => 'warning',
    'failed'  => 'danger',
    'skipped' => 'default',
];

$help = function (string $text): string {
    return '<i class="fa fa-question-circle text-muted cs-help"'
        . ' data-toggle="tooltip" data-placement="top"'
        . ' title="' . htmlspecialchars($text) . '"></i>';
};
?>
<div class="cs-widget" id="cs-widget">

  <ul class="nav nav-tabs cs-tabs">
    <li><a class="nav-link active" href="#cs-pane-list" data-toggle="tab"><?php echo _('My Schedules'); ?></a></li>
    <li><a class="nav-link" href="#cs-pane-history" data-toggle="tab"><?php echo _('History'); ?></a></li>
  </ul>

  <div class="tab-content">

    <!-- ===================== List pane ===================== -->
    <div class="tab-pane fade show active" id="cs-pane-list">
      <div class="cs-toolbar">
        <button type="button" class="btn btn-primary cs-btn-add">
          <i class="fa fa-plus"></i> <?php echo _('Add Schedule'); ?>
        </button>
        <button type="button" class="btn btn-default cs-btn-refresh" title="<?php echo _('Refresh'); ?>">
          <i class="fa fa-refresh"></i>
        </button>
      </div>

      <div class="table-responsive">
        <table class="table table-striped cs-table" id="cs-jobs-table">
          <thead>
            <tr>
              <th><?php echo _('Name'); ?></th>
              <th><?php echo _('Conference'); ?></th>
              <th><?php echo _('Next fire'); ?></th>
              <th><?php echo _('Last fire'); ?></th>
              <th><?php echo _('Status'); ?></th>
              <th><?php echo _('Enabled'); ?></th>
              <th class="text-right"><?php echo _('Actions'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($jobs)): ?>
              <tr class="cs-empty"><td colspan="7" class="text-center text-muted">
                <?php echo _('You have no schedules yet. Click "Add Schedule" to create one.'); ?>
              </td></tr>
            <?php else: foreach ($jobs as $job): ?>
              <tr data-job-id="<?php echo (int) $job['id']; ?>">
                <td>
                  <strong><?php echo htmlspecialchars($job['name']); ?></strong>
                  <?php if (!empty($job['description'])): ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($job['description']); ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <?php echo htmlspecialchars($job['conference_exten']); ?>
                  <?php if (!empty($job['conference_description'])): ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($job['conference_description']); ?></small>
                  <?php endif; ?>
                </td>
                <td><?php echo $fmt($job['next_fire_utc'] ?? null, $job['timezone'] ?? 'UTC'); ?></td>
                <td><?php echo $fmt($job['last_fire_utc'] ?? null, $job['timezone'] ?? 'UTC'); ?></td>
                <td>
                  <?php if (!empty($job['last_status'])): ?>
                    <span class="label label-<?php echo $statusClassMap[$job['last_status']] ?? 'default'; ?>">
                      <?php echo htmlspecialchars($job['last_status']); ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($job['enabled'])): ?>
                    <span class="label label-success"><?php echo _('Enabled'); ?></span>
                  <?php else: ?>
                    <span class="label label-default"><?php echo _('Disabled'); ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-right">
                  <button type="button" class="btn btn-sm btn-default cs-btn-edit" title="<?php echo _('Edit'); ?>">
                    <i class="fa fa-pencil"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-warning cs-btn-fire" title="<?php echo _('Fire Now'); ?>">
                    <i class="fa fa-play"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-danger cs-btn-delete" title="<?php echo _('Delete'); ?>">
                    <i class="fa fa-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ===================== History pane ===================== -->
    <div class="tab-pane fade" id="cs-pane-history">
      <div class="table-responsive">
        <table class="table table-striped cs-history-table">
          <thead>
            <tr>
              <th><?php echo _('Schedule'); ?></th>
              <th><?php echo _('Fired at (UTC)'); ?></th>
              <th><?php echo _('Status'); ?></th>
              <th><?php echo _('Legs'); ?></th>
              <th><?php echo _('Note'); ?></th>
            </tr>
          </thead>
          <tbody class="cs-history-rows">
            <?php if (empty($history)): ?>
              <tr><td colspan="5" class="text-center text-muted">
                <?php echo _('No history yet. Fire a schedule (or wait for the scheduler) to populate this table.'); ?>
              </td></tr>
            <?php else: foreach ($history as $h):
                $legs = json_decode((string) ($h['participants_json'] ?? '[]'), true) ?: [];
                $okCount = count(array_filter($legs, function ($l) {
                    return ($l['response'] ?? null) === 'Success';
                }));
            ?>
              <tr>
                <td><?php echo htmlspecialchars($h['job_name'] ?? sprintf('#%d', (int) $h['job_id'])); ?></td>
                <td><?php echo htmlspecialchars((string) $h['fired_at_utc']); ?> UTC</td>
                <td>
                  <span class="label label-<?php echo $statusClassMap[$h['status']] ?? 'default'; ?>">
                    <?php echo htmlspecialchars((string) $h['status']); ?>
                  </span>
                </td>
                <td><?php echo $okCount . ' / ' . count($legs); ?></td>
                <td>
                  <?php if (!empty($h['error_text'])): ?>
                    <small class="text-danger"><?php echo htmlspecialchars($h['error_text']); ?></small>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /.tab-content -->

</div><!-- /.cs-widget -->


<!-- ===================== Add/Edit Modal ===================== -->
<div class="modal fade cs-modal" id="cs-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title cs-modal-title"><?php echo _('New Schedule'); ?></h4>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger cs-form-error" style="display:none"></div>

        <form id="cs-form" onsubmit="return false">
          <input type="hidden" name="id" value="">

          <ul class="nav nav-tabs">
            <li><a class="nav-link active" href="#cs-tab-general" data-toggle="tab"><?php echo _('General'); ?></a></li>
            <li><a class="nav-link" href="#cs-tab-schedule" data-toggle="tab"><?php echo _('Schedule'); ?></a></li>
            <li><a class="nav-link" href="#cs-tab-participants" data-toggle="tab"><?php echo _('Participants'); ?></a></li>
            <li><a class="nav-link" href="#cs-tab-options" data-toggle="tab"><?php echo _('Options'); ?></a></li>
          </ul>

          <div class="tab-content cs-form-tabs">

            <!-- General -->
            <div class="tab-pane fade show active" id="cs-tab-general">
              <div class="form-group">
                <label><?php echo _('Name'); ?> <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" maxlength="190" required>
              </div>
              <div class="form-group">
                <label><?php echo _('Description'); ?></label>
                <textarea name="description" class="form-control" rows="2"></textarea>
              </div>
              <div class="form-group">
                <label><?php echo _('Conference room'); ?> <span class="text-danger">*</span></label>
                <select name="conference_exten" class="form-control" required>
                  <option value=""><?php echo _('-- pick a conference --'); ?></option>
                  <?php foreach ($conferences as $c): ?>
                    <option value="<?php echo htmlspecialchars($c['exten']); ?>">
                      <?php echo htmlspecialchars($c['exten']
                          . (empty($c['description']) ? '' : ' — ' . $c['description'])); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label><?php echo _('Timezone'); ?> <span class="text-danger">*</span></label>
                <select name="timezone" class="form-control" required>
                  <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                    <option value="<?php echo htmlspecialchars($tz); ?>"><?php echo htmlspecialchars($tz); ?></option>
                  <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">
                  <?php echo _('Daylight Saving Time is handled automatically — schedules fire at the same wall-clock time year-round. The preview below shows the abbreviation (e.g. CDT vs CST) that\'ll actually apply for each upcoming firing. For a zone that never shifts, pick '); ?>
                  <code>America/Regina</code>
                  <?php echo _(' (CST year-round).'); ?>
                </small>
              </div>
              <div class="checkbox">
                <label><input type="checkbox" name="enabled" value="1" checked> <?php echo _('Enabled'); ?></label>
                <p class="help-block"><?php echo _('Disabled schedules are not fired by the scheduler. Fire Now still works.'); ?></p>
              </div>
            </div>

            <!-- Schedule -->
            <div class="tab-pane fade" id="cs-tab-schedule">
              <div class="form-group">
                <label><?php echo _('Frequency'); ?></label>
                <select class="form-control cs-sched-freq" name="schedule[frequency]" style="max-width:380px">
                  <option value="oneoff"><?php echo _('One-off (specific date and time)'); ?></option>
                  <option value="daily"><?php echo _('Daily'); ?></option>
                  <option value="weekly" selected><?php echo _('Weekly'); ?></option>
                  <option value="monthly_dom"><?php echo _('Monthly (specific day of month)'); ?></option>
                  <option value="monthly_ordinal"><?php echo _('Monthly (Nth weekday — e.g. first Tuesday)'); ?></option>
                  <option value="quarterly_dom"><?php echo _('Quarterly (Jan, Apr, Jul, Oct on a specific day)'); ?></option>
                  <option value="custom_cron"><?php echo _('Custom cron expression (advanced)'); ?></option>
                </select>
              </div>

              <div class="cs-sched-section" data-types="oneoff">
                <div class="form-group">
                  <label><?php echo _('Date and time'); ?></label>
                  <input type="datetime-local" name="schedule[start_dt]" class="form-control" style="max-width:280px">
                </div>
              </div>

              <div class="cs-sched-section" data-types="daily">
                <div class="form-group">
                  <label><?php echo _('Time'); ?></label>
                  <input type="time" name="schedule[time]" class="form-control" style="max-width:200px" value="10:00">
                </div>
              </div>

              <div class="cs-sched-section" data-types="weekly">
                <div class="form-group">
                  <label><?php echo _('Days of week'); ?></label>
                  <div>
                    <?php foreach ($dowOptions as $code => $label): ?>
                      <label class="checkbox-inline" style="margin-right:10px">
                        <input type="checkbox" name="schedule[dows][]" value="<?php echo $code; ?>"
                          <?php if ($code === 'mon') {
                              echo ' checked';
                          } ?>>
                        <?php echo substr($label, 0, 3); ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="form-group">
                  <label><?php echo _('Time'); ?></label>
                  <input type="time" name="schedule[time]" class="form-control" style="max-width:200px" value="10:00">
                </div>
              </div>

              <div class="cs-sched-section" data-types="monthly_dom quarterly_dom">
                <div class="form-group">
                  <label><?php echo _('Day of month (1-28)'); ?></label>
                  <input type="number" name="schedule[dom]" class="form-control" style="max-width:120px" min="1" max="28" value="1">
                </div>
                <div class="form-group">
                  <label><?php echo _('Time'); ?></label>
                  <input type="time" name="schedule[time]" class="form-control" style="max-width:200px" value="10:00">
                </div>
              </div>

              <div class="cs-sched-section" data-types="monthly_ordinal">
                <div class="form-group form-inline">
                  <label><?php echo _('On the'); ?></label>
                  <select name="schedule[ordinal]" class="form-control" style="max-width:140px">
                    <?php foreach ($ordOptions as $val => $label): ?>
                      <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select name="schedule[dow]" class="form-control" style="max-width:160px">
                    <?php foreach ($dowOptions as $code => $label): ?>
                      <option value="<?php echo $code; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label><?php echo _('of every month at'); ?></label>
                  <input type="time" name="schedule[time]" class="form-control" style="max-width:160px" value="10:00">
                </div>
              </div>

              <div class="cs-sched-section" data-types="custom_cron">
                <div class="form-group">
                  <label><?php echo _('Cron expression (5 fields)'); ?></label>
                  <input type="text" name="schedule[cron_expr]" class="form-control" style="max-width:340px;font-family:monospace" placeholder="0 10 * * 2">
                  <p class="help-block">
                    <?php echo _('Format: minute hour day-of-month month day-of-week.'); ?>
                    <a href="https://crontab.guru/" target="_blank" rel="noopener">crontab.guru</a>
                  </p>
                </div>
              </div>

              <hr>
              <div class="cs-fire-preview">
                <p class="text-muted"><small><?php echo _('Pick a frequency to see the next 5 fire times.'); ?></small></p>
              </div>
            </div>

            <!-- Participants -->
            <div class="tab-pane fade" id="cs-tab-participants">
              <p class="text-muted"><?php echo _('Phones to ring when the schedule fires. Drag to reorder.'); ?></p>
              <table class="table cs-participants">
                <thead>
                  <tr>
                    <th style="width:30px"></th>
                    <th style="width:140px"><?php echo _('Kind'); ?></th>
                    <th><?php echo _('Number / Extension'); ?></th>
                    <th><?php echo _('Display name'); ?></th>
                    <th style="width:50px"></th>
                  </tr>
                </thead>
                <tbody class="cs-sortable"></tbody>
              </table>
              <button type="button" class="btn btn-default cs-add-participant">
                <i class="fa fa-plus"></i> <?php echo _('Add participant'); ?>
              </button>

              <template id="cs-participant-template">
                <tr class="cs-participant-row">
                  <td class="cs-drag-handle" style="cursor:move;text-align:center;color:#999"><i class="fa fa-bars"></i></td>
                  <td>
                    <select name="participants[__I__][kind]" class="form-control cs-kind">
                      <option value="extension"><?php echo _('Extension'); ?></option>
                      <option value="external"><?php echo _('External'); ?></option>
                    </select>
                  </td>
                  <td>
                    <select class="form-control cs-ext-picker">
                      <option value=""><?php echo _('-- pick an extension --'); ?></option>
                      <?php foreach ($extensions as $e): ?>
                        <option value="<?php echo htmlspecialchars($e['extension']); ?>">
                          <?php echo htmlspecialchars($e['extension']
                              . (empty($e['name']) ? '' : ' — ' . $e['name'])); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control cs-ext-text" name="participants[__I__][value]" placeholder="<?php echo _('e.g. 1001'); ?>" style="display:none">
                  </td>
                  <td><input type="text" name="participants[__I__][display_name]" class="form-control"></td>
                  <td class="text-right">
                    <input type="hidden" name="participants[__I__][sort_order]" class="cs-sort-order" value="0">
                    <button type="button" class="btn btn-sm btn-danger cs-remove-row"><i class="fa fa-times"></i></button>
                  </td>
                </tr>
              </template>
            </div>

            <!-- Options -->
            <div class="tab-pane fade" id="cs-tab-options">
              <div class="form-group">
                <label>
                  <?php echo _('Caller ID name'); ?>
                  <?php echo $help(_('Display name shown on participants\' phones. Leave blank to inherit from the outbound route.')); ?>
                </label>
                <input type="text" name="options[caller_id_name]" class="form-control" maxlength="64" placeholder="<?php echo _('(use outbound route default)'); ?>">
              </div>
              <div class="form-group">
                <label>
                  <?php echo _('Caller ID number'); ?>
                  <?php echo $help(_('Phone number shown on participants\' phones. Leave blank to inherit from the outbound route.')); ?>
                </label>
                <input type="text" name="options[caller_id_num]" class="form-control" maxlength="32" placeholder="<?php echo _('(use outbound route default)'); ?>">
              </div>
              <div class="form-group">
                <label>
                  <?php echo _('Wait time (seconds)'); ?>
                  <?php echo $help(_('How long Asterisk rings each participant before giving up on that leg. Range 5-300.')); ?>
                </label>
                <input type="number" name="options[wait_time_sec]" class="form-control" style="max-width:200px" min="5" max="300" value="45">
              </div>
              <div class="form-group">
                <label>
                  <?php echo _('Concurrency policy'); ?>
                  <?php echo $help(_('Skip if already in room: don\'t dial participants who are currently in the conference. Force new: ring everyone, even those already in.')); ?>
                </label>
                <select name="options[concurrency_policy]" class="form-control" style="max-width:420px">
                  <option value="skip_if_active"><?php echo _('Skip participants already in the conference (default)'); ?></option>
                  <option value="force_new"><?php echo _('Ring everyone (even those already in the room)'); ?></option>
                </select>
              </div>
            </div>

          </div><!-- /.tab-content -->
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-danger cs-modal-delete pull-left" style="display:none">
          <i class="fa fa-trash"></i> <?php echo _('Delete'); ?>
        </button>
        <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _('Cancel'); ?></button>
        <button type="button" class="btn btn-primary cs-modal-save">
          <i class="fa fa-save"></i> <?php echo _('Save'); ?>
        </button>
      </div>
    </div>
  </div>
</div>
