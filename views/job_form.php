<?php

// SPDX-License-Identifier: Apache-2.0
//
// Job create / edit form. Receives:
//   $job          - hydrated job array (from getJob, or $_POST on validation error)
//                   May be null when adding.
//   $conferences  - array of ['exten' => ..., 'description' => ...] from listConferences()
//   $extensions   - array of ['extension' => ..., 'name' => ...] from listExtensions()
//   $error        - string|null validation error to display
//   $flash        - flash array | null

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

$isEdit  = !empty($job['id']);
$jobId   = $isEdit ? (int) $job['id'] : null;
$jobName = $job['name']             ?? '';
$jobDesc = $job['description']      ?? '';
$jobConf = $job['conference_exten'] ?? '';
$jobTz   = $job['timezone']         ?? date_default_timezone_get();
$jobOn   = isset($job['enabled']) ? (int) $job['enabled'] : 1;

// Schedule pre-fill. Three sources, in priority order:
//   1. $_POST['schedule'] when the form is being redisplayed after a validation error.
//   2. The first row of $job['schedules'] reverse-parsed via Validators::explainSchedule.
//   3. Default to weekly at 10:00.
$sched = ['frequency' => 'weekly', 'time' => '10:00', 'dows' => []];
if (!empty($job['schedule']) && is_array($job['schedule'])) {
    $sched = array_merge($sched, $job['schedule']);
} elseif (!empty($job['schedules'][0])) {
    $sched = array_merge(
        $sched,
        \FreePBX\modules\Conferenceschedules\Validators::explainSchedule($job['schedules'][0])
    );
}

$opt = $job['options'] ?? [];

$participants = is_array($job['participants'] ?? null) ? $job['participants'] : [];

$dowOptions = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
               'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
$ordOptions = ['1' => _('First'), '2' => _('Second'), '3' => _('Third'),
               '4' => _('Fourth'), 'L' => _('Last')];

/**
 * Render a small tooltip icon. Bootstrap 3 tooltips need JS init —
 * conferenceschedules.js does that on DOM ready.
 */
$help = function (string $text): string {
    return '<i class="fa fa-question-circle text-muted" style="margin-left:4px"'
        . ' data-toggle="tooltip" data-placement="right"'
        . ' title="' . htmlspecialchars($text) . '"></i>';
};
?>
<h1><?php echo $isEdit ? _('Edit Conference Schedule') : _('New Conference Schedule'); ?></h1>

<?php if ($flash): ?>
  <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
    <?php echo htmlspecialchars($flash['msg']); ?>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="post" action="?display=conferenceschedules&amp;action=save" id="cs-job-form">
  <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?php echo $jobId; ?>">
  <?php endif; ?>

  <ul class="nav nav-tabs" role="tablist">
    <li class="active"><a href="#cs-tab-general" data-toggle="tab"><?php echo _('General'); ?></a></li>
    <li><a href="#cs-tab-schedule" data-toggle="tab"><?php echo _('Schedule'); ?></a></li>
    <li><a href="#cs-tab-participants" data-toggle="tab"><?php echo _('Participants'); ?></a></li>
    <li><a href="#cs-tab-options" data-toggle="tab"><?php echo _('Options'); ?></a></li>
  </ul>

  <div class="tab-content cs-tab-content">

    <!-- ============== General ============== -->
    <div class="tab-pane active" id="cs-tab-general">
      <div class="form-group">
        <label for="cs-name"><?php echo _('Name'); ?> <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="cs-name" name="name" maxlength="190"
               required value="<?php echo htmlspecialchars($jobName); ?>">
      </div>

      <div class="form-group">
        <label for="cs-description"><?php echo _('Description'); ?></label>
        <textarea class="form-control" id="cs-description" name="description" rows="2"><?php
            echo htmlspecialchars($jobDesc);
        ?></textarea>
      </div>

      <div class="form-group">
        <label for="cs-conf"><?php echo _('Conference room'); ?> <span class="text-danger">*</span></label>
        <select class="form-control" id="cs-conf" name="conference_exten" required>
          <option value=""><?php echo _('-- pick a conference --'); ?></option>
          <?php foreach ($conferences as $conf): ?>
            <option value="<?php echo htmlspecialchars($conf['exten']); ?>"
              <?php if ($jobConf === $conf['exten']) {
                  echo ' selected';
              } ?>>
              <?php echo htmlspecialchars(
                  $conf['exten'] . (empty($conf['description']) ? '' : ' — ' . $conf['description'])
              ); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="help-block">
          <?php echo _('Conferences are managed in the standard Conferences module.'); ?>
        </p>
      </div>

      <div class="form-group">
        <label for="cs-tz"><?php echo _('Timezone'); ?> <span class="text-danger">*</span></label>
        <select class="form-control" id="cs-tz" name="timezone" required>
          <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
            <option value="<?php echo htmlspecialchars($tz); ?>"
              <?php if ($jobTz === $tz) {
                  echo ' selected';
              } ?>>
              <?php echo htmlspecialchars($tz); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="help-block">
          <?php echo _('Daylight Saving Time is handled automatically — schedules fire at the same wall-clock time year-round in the chosen zone (e.g. 10:00 AM is still 10:00 AM after the spring forward / fall back). To use a zone that never observes DST, pick a fixed-offset zone like '); ?>
          <code>America/Regina</code>
          <?php echo _(' (CST year-round) or '); ?>
          <code>Etc/GMT+6</code>.
        </p>
      </div>

      <div class="form-group">
        <label class="checkbox-inline">
          <input type="checkbox" name="enabled" value="1"
            <?php if ($jobOn) {
                echo ' checked';
            } ?>>
          <?php echo _('Enabled'); ?>
        </label>
        <p class="help-block">
          <?php echo _('Disabled schedules are not fired by the scheduler. Fire Now still works.'); ?>
        </p>
      </div>
    </div>

    <!-- ============== Schedule ============== -->
    <div class="tab-pane" id="cs-tab-schedule">
      <div class="form-group">
        <label for="cs-sched-freq"><?php echo _('Frequency'); ?></label>
        <select class="form-control" id="cs-sched-freq" name="schedule[frequency]" style="max-width:380px">
          <option value="oneoff"           <?php if ($sched['frequency'] === 'oneoff') {
              echo ' selected';
          } ?>><?php echo _('One-off (specific date and time)'); ?></option>
          <option value="daily"            <?php if ($sched['frequency'] === 'daily') {
              echo ' selected';
          } ?>><?php echo _('Daily'); ?></option>
          <option value="weekly"           <?php if ($sched['frequency'] === 'weekly') {
              echo ' selected';
          } ?>><?php echo _('Weekly'); ?></option>
          <option value="monthly_dom"      <?php if ($sched['frequency'] === 'monthly_dom') {
              echo ' selected';
          } ?>><?php echo _('Monthly (specific day of month)'); ?></option>
          <option value="monthly_ordinal"  <?php if ($sched['frequency'] === 'monthly_ordinal') {
              echo ' selected';
          } ?>><?php echo _('Monthly (Nth weekday — e.g. first Tuesday)'); ?></option>
          <option value="quarterly_dom"    <?php if ($sched['frequency'] === 'quarterly_dom') {
              echo ' selected';
          } ?>><?php echo _('Quarterly (Jan, Apr, Jul, Oct on a specific day)'); ?></option>
          <option value="custom_cron"      <?php if ($sched['frequency'] === 'custom_cron') {
              echo ' selected';
          } ?>><?php echo _('Custom cron expression (advanced)'); ?></option>
        </select>
      </div>

      <!-- One-off -->
      <div class="cs-sched-section" data-types="oneoff">
        <div class="form-group">
          <label for="cs-sched-startdt"><?php echo _('Date and time'); ?></label>
          <input type="datetime-local" class="form-control" id="cs-sched-startdt"
                 name="schedule[start_dt]" style="max-width:280px"
                 value="<?php echo htmlspecialchars($sched['start_dt'] ?? ''); ?>">
        </div>
      </div>

      <!-- Daily -->
      <div class="cs-sched-section" data-types="daily">
        <div class="form-group">
          <label for="cs-sched-daily-time"><?php echo _('Time (24h, in schedule timezone)'); ?></label>
          <input type="time" class="form-control" id="cs-sched-daily-time"
                 name="schedule[time]" style="max-width:200px"
                 value="<?php echo htmlspecialchars($sched['time'] ?? '10:00'); ?>"
                 data-cs-shared-time="1">
        </div>
      </div>

      <!-- Weekly -->
      <div class="cs-sched-section" data-types="weekly">
        <div class="form-group">
          <label><?php echo _('Days of week'); ?></label>
          <div>
            <?php foreach ($dowOptions as $code => $label): ?>
              <label class="checkbox-inline">
                <input type="checkbox" name="schedule[dows][]" value="<?php echo $code; ?>"
                  <?php if (in_array($code, (array) ($sched['dows'] ?? []), true)) {
                      echo ' checked';
                  } ?>>
                <?php echo substr($label, 0, 3); ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label for="cs-sched-weekly-time"><?php echo _('Time (24h, in schedule timezone)'); ?></label>
          <input type="time" class="form-control" id="cs-sched-weekly-time"
                 name="schedule[time]" style="max-width:200px"
                 value="<?php echo htmlspecialchars($sched['time'] ?? '10:00'); ?>"
                 data-cs-shared-time="1">
        </div>
      </div>

      <!-- Monthly (specific day) -->
      <div class="cs-sched-section" data-types="monthly_dom quarterly_dom">
        <div class="form-group">
          <label for="cs-sched-dom"><?php echo _('Day of month'); ?></label>
          <input type="number" class="form-control" id="cs-sched-dom"
                 name="schedule[dom]" style="max-width:120px"
                 min="1" max="28"
                 value="<?php echo (int) ($sched['dom'] ?? 1); ?>">
          <p class="help-block">
            <?php echo _('1-28 (capped at 28 so February is always covered).'); ?>
          </p>
        </div>
        <div class="form-group">
          <label for="cs-sched-monthly-time"><?php echo _('Time (24h, in schedule timezone)'); ?></label>
          <input type="time" class="form-control" id="cs-sched-monthly-time"
                 name="schedule[time]" style="max-width:200px"
                 value="<?php echo htmlspecialchars($sched['time'] ?? '10:00'); ?>"
                 data-cs-shared-time="1">
        </div>
      </div>

      <!-- Monthly (Nth weekday) -->
      <div class="cs-sched-section" data-types="monthly_ordinal">
        <div class="form-group form-inline">
          <label><?php echo _('On the'); ?></label>
          <select name="schedule[ordinal]" class="form-control" style="max-width:140px">
            <?php foreach ($ordOptions as $val => $label): ?>
              <option value="<?php echo $val; ?>"
                <?php if (($sched['ordinal'] ?? '') === $val) {
                    echo ' selected';
                } ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
          </select>
          <select name="schedule[dow]" class="form-control" style="max-width:160px">
            <?php foreach ($dowOptions as $code => $label): ?>
              <option value="<?php echo $code; ?>"
                <?php if (($sched['dow'] ?? '') === $code) {
                    echo ' selected';
                } ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
          </select>
          <label><?php echo _('of every month at'); ?></label>
          <input type="time" class="form-control" name="schedule[time]"
                 style="max-width:160px"
                 value="<?php echo htmlspecialchars($sched['time'] ?? '10:00'); ?>"
                 data-cs-shared-time="1">
        </div>
      </div>

      <!-- Custom cron -->
      <div class="cs-sched-section" data-types="custom_cron">
        <div class="form-group">
          <label for="cs-sched-cron"><?php echo _('Cron expression (5 fields)'); ?></label>
          <input type="text" class="form-control" id="cs-sched-cron"
                 name="schedule[cron_expr]" style="max-width:340px;font-family:monospace"
                 placeholder="0 10 * * 2"
                 value="<?php echo htmlspecialchars($sched['cron_expr'] ?? ''); ?>">
          <p class="help-block">
            <?php echo _('Format: minute hour day-of-month month day-of-week.'); ?>
            <a href="https://crontab.guru/" target="_blank" rel="noopener">crontab.guru</a>
          </p>
        </div>
      </div>

      <hr>
      <div id="cs-fire-preview-wrap">
        <p class="text-muted">
          <small><?php echo _('Pick a frequency to see the next 5 fire times.'); ?></small>
        </p>
      </div>
    </div>

    <!-- ============== Participants ============== -->
    <div class="tab-pane" id="cs-tab-participants">
      <p class="text-muted">
        <?php echo _('Phones to ring when the schedule fires. Drag rows to reorder; the order determines fire order.'); ?>
      </p>

      <table class="table" id="cs-participants-table">
        <thead>
          <tr>
            <th style="width:30px"></th>
            <th style="width:140px"><?php echo _('Kind'); ?></th>
            <th><?php echo _('Number / Extension'); ?></th>
            <th><?php echo _('Display name'); ?></th>
            <th style="width:50px"></th>
          </tr>
        </thead>
        <tbody class="cs-sortable">
          <?php foreach ($participants as $i => $p):
              $kind = (string) ($p['kind'] ?? 'extension');
              $value = (string) ($p['value'] ?? '');
              $matchesExt = false;
              if ($kind === 'extension') {
                  foreach ($extensions as $e) {
                      if ($e['extension'] === $value) {
                          $matchesExt = true;
                          break;
                      }
                  }
              }
          ?>
            <tr class="cs-participant-row">
              <td class="cs-drag-handle" style="cursor:move;text-align:center;color:#999">
                <i class="fa fa-bars"></i>
              </td>
              <td>
                <select name="participants[<?php echo $i; ?>][kind]" class="form-control cs-kind">
                  <option value="extension"
                    <?php if ($kind === 'extension') {
                        echo ' selected';
                    } ?>><?php echo _('Extension'); ?></option>
                  <option value="external"
                    <?php if ($kind === 'external') {
                        echo ' selected';
                    } ?>><?php echo _('External'); ?></option>
                </select>
              </td>
              <td>
                <select class="form-control cs-ext-picker"
                        data-target-name="participants[<?php echo $i; ?>][value]"
                        <?php if ($kind !== 'extension') {
                            echo 'style="display:none"';
                        } ?>>
                  <option value=""><?php echo _('-- pick an extension --'); ?></option>
                  <?php foreach ($extensions as $e): ?>
                    <option value="<?php echo htmlspecialchars($e['extension']); ?>"
                      <?php if ($matchesExt && $e['extension'] === $value) {
                          echo ' selected';
                      } ?>>
                      <?php echo htmlspecialchars(
                          $e['extension']
                          . (empty($e['name']) ? '' : ' — ' . $e['name'])
                      ); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="text" class="form-control cs-ext-text"
                       name="participants[<?php echo $i; ?>][value]"
                       value="<?php echo htmlspecialchars($value); ?>"
                       placeholder="<?php echo $kind === 'external'
                           ? '+15551234567'
                           : _('e.g. 1001'); ?>"
                       <?php if ($kind === 'extension') {
                           echo 'style="display:none"';
                       } ?>>
              </td>
              <td>
                <input type="text" name="participants[<?php echo $i; ?>][display_name]"
                       class="form-control"
                       value="<?php echo htmlspecialchars($p['display_name'] ?? ''); ?>">
              </td>
              <td class="text-right">
                <input type="hidden" name="participants[<?php echo $i; ?>][sort_order]"
                       class="cs-sort-order" value="<?php echo (int) ($p['sort_order'] ?? $i); ?>">
                <button type="button" class="btn btn-sm btn-danger cs-remove-row"
                        title="<?php echo _('Remove'); ?>">
                  <i class="fa fa-times"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <button type="button" class="btn btn-default" id="cs-add-participant">
        <i class="fa fa-plus"></i> <?php echo _('Add participant'); ?>
      </button>

      <!-- Template row used by the JS to append new participants. -->
      <template id="cs-participant-template">
        <tr class="cs-participant-row">
          <td class="cs-drag-handle" style="cursor:move;text-align:center;color:#999">
            <i class="fa fa-bars"></i>
          </td>
          <td>
            <select name="participants[__I__][kind]" class="form-control cs-kind">
              <option value="extension"><?php echo _('Extension'); ?></option>
              <option value="external"><?php echo _('External'); ?></option>
            </select>
          </td>
          <td>
            <select class="form-control cs-ext-picker"
                    data-target-name="participants[__I__][value]">
              <option value=""><?php echo _('-- pick an extension --'); ?></option>
              <?php foreach ($extensions as $e): ?>
                <option value="<?php echo htmlspecialchars($e['extension']); ?>">
                  <?php echo htmlspecialchars(
                      $e['extension']
                      . (empty($e['name']) ? '' : ' — ' . $e['name'])
                  ); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="text" class="form-control cs-ext-text"
                   name="participants[__I__][value]"
                   placeholder="<?php echo _('e.g. 1001'); ?>" style="display:none">
          </td>
          <td><input type="text" name="participants[__I__][display_name]" class="form-control"></td>
          <td class="text-right">
            <input type="hidden" name="participants[__I__][sort_order]" class="cs-sort-order" value="0">
            <button type="button" class="btn btn-sm btn-danger cs-remove-row">
              <i class="fa fa-times"></i>
            </button>
          </td>
        </tr>
      </template>
    </div>

    <!-- ============== Options ============== -->
    <div class="tab-pane" id="cs-tab-options">
      <div class="form-group">
        <label for="cs-cidn">
          <?php echo _('Caller ID name'); ?>
          <?php echo $help(_('Display name shown on participants\' phones when they ring. Leave blank to inherit the caller ID set on the outbound route used to reach the number.')); ?>
        </label>
        <input type="text" class="form-control" id="cs-cidn" name="options[caller_id_name]"
               maxlength="64"
               placeholder="<?php echo _('(use outbound route default)'); ?>"
               value="<?php echo htmlspecialchars($opt['caller_id_name'] ?? ''); ?>">
      </div>

      <div class="form-group">
        <label for="cs-cidnum">
          <?php echo _('Caller ID number'); ?>
          <?php echo $help(_('Phone number shown on participants\' phones. Leave blank to inherit the outbound route\'s caller ID.')); ?>
        </label>
        <input type="text" class="form-control" id="cs-cidnum" name="options[caller_id_num]"
               maxlength="32"
               placeholder="<?php echo _('(use outbound route default)'); ?>"
               value="<?php echo htmlspecialchars($opt['caller_id_num'] ?? ''); ?>">
      </div>

      <div class="form-group">
        <label for="cs-wait">
          <?php echo _('Wait time (seconds)'); ?>
          <?php echo $help(_('How long Asterisk rings each participant before giving up on that leg. Range 5-300.')); ?>
        </label>
        <input type="number" class="form-control" id="cs-wait" name="options[wait_time_sec]"
               style="max-width:200px"
               min="5" max="300" value="<?php echo (int) ($opt['wait_time_sec'] ?? 45); ?>">
      </div>

      <div class="form-group">
        <label for="cs-cp">
          <?php echo _('Concurrency policy'); ?>
          <?php echo $help(_('Skip if already in room: when the schedule fires, don\'t dial participants who are currently in the conference room — only ring the others (default). Force new: dial every participant, even those already in the room.')); ?>
        </label>
        <select class="form-control" id="cs-cp" name="options[concurrency_policy]" style="max-width:420px">
          <option value="skip_if_active"
            <?php if (($opt['concurrency_policy'] ?? 'skip_if_active') === 'skip_if_active') {
                echo ' selected';
            } ?>>
            <?php echo _('Skip participants already in the conference (default)'); ?>
          </option>
          <option value="force_new"
            <?php if (($opt['concurrency_policy'] ?? '') === 'force_new') {
                echo ' selected';
            } ?>>
            <?php echo _('Ring everyone (even those already in the room)'); ?>
          </option>
        </select>
      </div>
    </div>

  </div><!-- /.tab-content -->

  <hr>
  <div>
    <button type="submit" class="btn btn-primary">
      <i class="fa fa-save"></i> <?php echo _('Save'); ?>
    </button>
    <a href="?display=conferenceschedules" class="btn btn-default">
      <?php echo _('Cancel'); ?>
    </a>
    <?php if ($isEdit): ?>
      <a href="?display=conferenceschedules&amp;action=delete&amp;id=<?php echo $jobId; ?>"
         class="btn btn-danger pull-right"
         onclick="return confirm('<?php echo _('Delete this schedule? This cannot be undone.'); ?>');">
        <i class="fa fa-trash"></i> <?php echo _('Delete'); ?>
      </a>
    <?php endif; ?>
  </div>
</form>
