<?php

// SPDX-License-Identifier: Apache-2.0
//
// Job create / edit form. Receives:
//   $job          - hydrated job array (from getJob, or $_POST on validation error)
//                   May be null when adding.
//   $conferences  - array of ['exten' => ..., 'description' => ...] from listConferences()
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

// Phase 1: only "Quick recurring" schedule mode.
//   - On render after POST error: $job is $_POST, so $job['quick_dows'] +
//     $job['quick_time'] are present.
//   - On render from getJob: parse the stored cron_expr back into DOW + time.
$quickDows = [];
$quickTime = '10:00';

if (isset($job['quick_dows']) && is_array($job['quick_dows'])) {
    $quickDows = array_map('strval', $job['quick_dows']);
    $quickTime = (string) ($job['quick_time'] ?? '10:00');
} elseif (!empty($job['schedules'][0]['cron_expr'])) {
    $expr = $job['schedules'][0]['cron_expr'];
    if (preg_match('/^(\d+)\s+(\d+)\s+\*\s+\*\s+([\d,]+)$/', $expr, $m)) {
        $quickTime = sprintf('%02d:%02d', (int) $m[2], (int) $m[1]);
        $codeMap = [
            0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed',
            4 => 'thu', 5 => 'fri', 6 => 'sat',
        ];
        foreach (explode(',', $m[3]) as $code) {
            $code = (int) $code;
            if (isset($codeMap[$code])) {
                $quickDows[] = $codeMap[$code];
            }
        }
    }
}

$opt = $job['options'] ?? [];

$participants = is_array($job['participants'] ?? null) ? $job['participants'] : [];
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

    <!-- General -->
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
          <?php echo _('Disabled jobs are not fired by the scheduler. Fire Now still works.'); ?>
        </p>
      </div>
    </div>

    <!-- Schedule (Quick Recurring) -->
    <div class="tab-pane" id="cs-tab-schedule">
      <p class="text-muted">
        <?php echo _('Pick day(s) of the week and a time. The scheduler fires the job at the next matching slot in the job\'s timezone.'); ?>
      </p>

      <div class="form-group">
        <label><?php echo _('Days of week'); ?></label>
        <div>
          <?php foreach (
              ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu',
               'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'] as $code => $label
          ): ?>
            <label class="checkbox-inline">
              <input type="checkbox" name="quick_dows[]" value="<?php echo $code; ?>"
                <?php if (in_array($code, $quickDows, true)) {
                    echo ' checked';
                } ?>>
              <?php echo $label; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-group">
        <label for="cs-time"><?php echo _('Time (24h, in job timezone)'); ?></label>
        <input type="time" class="form-control" id="cs-time" name="quick_time"
               style="max-width: 200px"
               value="<?php echo htmlspecialchars($quickTime); ?>">
      </div>

      <div class="form-group" id="cs-cron-preview-wrap">
        <p class="text-muted">
          <small><?php echo _('Pick at least one day and a time to see the next 5 fire times.'); ?></small>
        </p>
      </div>
    </div>

    <!-- Participants -->
    <div class="tab-pane" id="cs-tab-participants">
      <p class="text-muted">
        <?php echo _('Phones to ring when the job fires. Use Sort Order to control fire order (low to high).'); ?>
      </p>

      <table class="table" id="cs-participants-table">
        <thead>
          <tr>
            <th style="width:80px"><?php echo _('Order'); ?></th>
            <th style="width:150px"><?php echo _('Kind'); ?></th>
            <th><?php echo _('Number / Extension'); ?></th>
            <th><?php echo _('Display name'); ?></th>
            <th style="width:60px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($participants as $i => $p): ?>
            <tr class="cs-participant-row">
              <td>
                <input type="number" name="participants[<?php echo $i; ?>][sort_order]"
                       class="form-control"
                       value="<?php echo (int) ($p['sort_order'] ?? $i); ?>" min="0">
              </td>
              <td>
                <select name="participants[<?php echo $i; ?>][kind]" class="form-control">
                  <option value="extension"
                    <?php if (($p['kind'] ?? '') === 'extension') {
                        echo ' selected';
                    } ?>>
                    <?php echo _('Extension'); ?>
                  </option>
                  <option value="external"
                    <?php if (($p['kind'] ?? '') === 'external') {
                        echo ' selected';
                    } ?>>
                    <?php echo _('External'); ?>
                  </option>
                </select>
              </td>
              <td>
                <input type="text" name="participants[<?php echo $i; ?>][value]"
                       class="form-control"
                       value="<?php echo htmlspecialchars($p['value'] ?? ''); ?>" required>
              </td>
              <td>
                <input type="text" name="participants[<?php echo $i; ?>][display_name]"
                       class="form-control"
                       value="<?php echo htmlspecialchars($p['display_name'] ?? ''); ?>">
              </td>
              <td class="text-right">
                <button type="button" class="btn btn-sm btn-danger cs-remove-row" title="<?php echo _('Remove'); ?>">
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
          <td>
            <input type="number" name="participants[__I__][sort_order]" class="form-control" value="0" min="0">
          </td>
          <td>
            <select name="participants[__I__][kind]" class="form-control">
              <option value="extension"><?php echo _('Extension'); ?></option>
              <option value="external"><?php echo _('External'); ?></option>
            </select>
          </td>
          <td><input type="text" name="participants[__I__][value]" class="form-control" required></td>
          <td><input type="text" name="participants[__I__][display_name]" class="form-control"></td>
          <td class="text-right">
            <button type="button" class="btn btn-sm btn-danger cs-remove-row">
              <i class="fa fa-times"></i>
            </button>
          </td>
        </tr>
      </template>
    </div>

    <!-- Options -->
    <div class="tab-pane" id="cs-tab-options">
      <div class="form-group">
        <label for="cs-cidn"><?php echo _('Caller ID name'); ?></label>
        <input type="text" class="form-control" id="cs-cidn" name="options[caller_id_name]"
               maxlength="64" value="<?php echo htmlspecialchars($opt['caller_id_name'] ?? ''); ?>">
      </div>

      <div class="form-group">
        <label for="cs-cidnum"><?php echo _('Caller ID number'); ?></label>
        <input type="text" class="form-control" id="cs-cidnum" name="options[caller_id_num]"
               maxlength="32" value="<?php echo htmlspecialchars($opt['caller_id_num'] ?? ''); ?>">
      </div>

      <div class="form-group">
        <label for="cs-wait"><?php echo _('Wait time (seconds)'); ?></label>
        <input type="number" class="form-control" id="cs-wait" name="options[wait_time_sec]"
               style="max-width: 200px"
               min="5" max="300" value="<?php echo (int) ($opt['wait_time_sec'] ?? 45); ?>">
        <p class="help-block">
          <?php echo _('How long to ring each leg before giving up (5-300).'); ?>
        </p>
      </div>

      <div class="form-group">
        <label for="cs-cp"><?php echo _('Concurrency policy'); ?></label>
        <select class="form-control" id="cs-cp" name="options[concurrency_policy]">
          <option value="skip_if_active"
            <?php if (($opt['concurrency_policy'] ?? 'skip_if_active') === 'skip_if_active') {
                echo ' selected';
            } ?>>
            <?php echo _('Skip if conference is already active'); ?>
          </option>
          <option value="force_new"
            <?php if (($opt['concurrency_policy'] ?? '') === 'force_new') {
                echo ' selected';
            } ?>>
            <?php echo _('Fire anyway (force new)'); ?>
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
         onclick="return confirm('<?php echo _('Delete this job? This cannot be undone.'); ?>');">
        <i class="fa fa-trash"></i> <?php echo _('Delete'); ?>
      </a>
    <?php endif; ?>
  </div>
</form>
