<?php

// SPDX-License-Identifier: Apache-2.0
//
// History view. Receives:
//   $rows       - array<int,array> from Conferenceschedules::listHistory()
//   $jobs       - array<int,array> from listJobs() (for filter dropdown)
//   $jobFilter  - int|null currently selected job filter
//   $flash      - flash array | null

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

$statusLabel = function (?string $status): string {
    $map = [
        'success' => 'success',
        'partial' => 'warning',
        'failed'  => 'danger',
        'skipped' => 'default',
    ];
    return $map[$status ?? ''] ?? 'default';
};

$fmtUtc = function (?string $utc): string {
    if (empty($utc)) {
        return '—';
    }
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        return htmlspecialchars($dt->format('Y-m-d H:i:s')) . ' UTC';
    } catch (\Exception $e) {
        return htmlspecialchars($utc);
    }
};
?>
<h1><?php echo _('Conference Schedules — History'); ?></h1>

<?php if ($flash): ?>
  <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
    <?php echo htmlspecialchars($flash['msg']); ?>
  </div>
<?php endif; ?>

<form method="get" class="form-inline" style="margin-bottom: 16px">
  <input type="hidden" name="display" value="conferenceschedules">
  <input type="hidden" name="view" value="history">
  <div class="form-group">
    <label for="cs-h-job"><?php echo _('Filter by schedule'); ?>:</label>
    <select name="job_id" id="cs-h-job" class="form-control" onchange="this.form.submit()">
      <option value=""><?php echo _('— all schedules —'); ?></option>
      <?php foreach ($jobs as $j): ?>
        <option value="<?php echo (int) $j['id']; ?>"
          <?php if ($jobFilter === (int) $j['id']) {
              echo ' selected';
          } ?>>
          <?php echo htmlspecialchars($j['name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <a href="?display=conferenceschedules" class="btn btn-default">
    <i class="fa fa-arrow-left"></i> <?php echo _('Back to schedules'); ?>
  </a>
</form>

<table class="table table-striped">
  <thead>
    <tr>
      <th style="width:30px"></th>
      <th><?php echo _('Schedule'); ?></th>
      <th><?php echo _('Fired at (UTC)'); ?></th>
      <th><?php echo _('Status'); ?></th>
      <th><?php echo _('Legs'); ?></th>
      <th><?php echo _('Note'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($rows)): ?>
      <tr>
        <td colspan="6" class="text-center text-muted">
          <?php echo _('No history yet. Fire a schedule (or wait for the scheduler) to populate this table.'); ?>
        </td>
      </tr>
    <?php else: foreach ($rows as $row): ?>
      <?php
        $rowId = (int) $row['id'];
        $legs  = json_decode((string) ($row['participants_json'] ?? '[]'), true) ?: [];
      ?>
      <tr>
        <td>
          <?php if (!empty($legs)): ?>
            <a href="#cs-legs-<?php echo $rowId; ?>" data-toggle="collapse"
               aria-expanded="false" aria-controls="cs-legs-<?php echo $rowId; ?>"
               title="<?php echo _('Show legs'); ?>">
              <i class="fa fa-chevron-right"></i>
            </a>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!empty($row['job_name'])): ?>
            <a href="?display=conferenceschedules&amp;action=edit&amp;id=<?php echo (int) $row['job_id']; ?>">
              <?php echo htmlspecialchars($row['job_name']); ?>
            </a>
          <?php else: ?>
            <span class="text-muted">
              <?php echo htmlspecialchars(sprintf(_('(deleted schedule %d)'), (int) $row['job_id'])); ?>
            </span>
          <?php endif; ?>
        </td>
        <td><?php echo $fmtUtc($row['fired_at_utc']); ?></td>
        <td>
          <span class="label label-<?php echo $statusLabel($row['status']); ?>">
            <?php echo htmlspecialchars($row['status']); ?>
          </span>
        </td>
        <td>
          <?php
            $okCount = count(array_filter(
                $legs,
                function ($l) {
                    return ($l['response'] ?? null) === 'Success';
                }
            ));
            $total = count($legs);
            echo $total === 0 ? '—' : htmlspecialchars($okCount . ' / ' . $total);
          ?>
        </td>
        <td>
          <?php if (!empty($row['error_text'])): ?>
            <small class="text-danger"><?php echo htmlspecialchars($row['error_text']); ?></small>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php if (!empty($legs)): ?>
        <tr id="cs-legs-<?php echo $rowId; ?>" class="collapse">
          <td></td>
          <td colspan="5">
            <table class="table table-condensed" style="background:#fafafa">
              <thead>
                <tr>
                  <th><?php echo _('Kind'); ?></th>
                  <th><?php echo _('Number / Ext'); ?></th>
                  <th><?php echo _('Display name'); ?></th>
                  <th><?php echo _('Channel'); ?></th>
                  <th><?php echo _('AMI Response'); ?></th>
                  <th><?php echo _('Message'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($legs as $leg): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($leg['kind'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($leg['value'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($leg['display_name'] ?? ''); ?></td>
                    <td><code><?php echo htmlspecialchars($leg['channel'] ?? ''); ?></code></td>
                    <td>
                      <?php $resp = (string) ($leg['response'] ?? '?'); ?>
                      <span class="label label-<?php echo $resp === 'Success' ? 'success' : 'danger'; ?>">
                        <?php echo htmlspecialchars($resp); ?>
                      </span>
                    </td>
                    <td>
                      <small class="text-muted">
                        <?php echo htmlspecialchars((string) ($leg['message'] ?? '')); ?>
                      </small>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </td>
        </tr>
      <?php endif; ?>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<p class="text-muted">
  <small><?php echo sprintf(_('Showing %d most recent rows.'), count($rows)); ?></small>
</p>
