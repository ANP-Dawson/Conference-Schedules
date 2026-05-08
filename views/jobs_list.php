<?php

// SPDX-License-Identifier: Apache-2.0
//
// Jobs list view. Receives:
//   $jobs  - array<int,array> from Conferenceschedules::listJobs()
//   $flash - ['type' => 'success'|'info'|'danger', 'msg' => string] | null

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

/**
 * Format a UTC DATETIME as a friendly string in the job's local timezone.
 * Returns em-dash for null/empty.
 */
$fmt = function (?string $utc, ?string $tz): string {
    if (empty($utc)) {
        return '—';
    }
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz ?: 'UTC'));
        return htmlspecialchars($dt->format('Y-m-d H:i T'));
    } catch (\Exception $e) {
        return htmlspecialchars($utc) . ' UTC';
    }
};

$statusLabel = function (?string $status): string {
    $map = [
        'success' => 'success',
        'partial' => 'warning',
        'failed'  => 'danger',
        'skipped' => 'default',
    ];
    return $map[$status ?? ''] ?? 'default';
};
?>
<h1><?php echo _('Conference Schedules'); ?></h1>

<?php if ($flash): ?>
  <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
    <?php echo htmlspecialchars($flash['msg']); ?>
  </div>
<?php endif; ?>

<p>
  <a href="?display=conferenceschedules&amp;action=add" class="btn btn-primary">
    <i class="fa fa-plus"></i> <?php echo _('Add new schedule'); ?>
  </a>
  <a href="?display=conferenceschedules&amp;view=history" class="btn btn-default">
    <i class="fa fa-history"></i> <?php echo _('History'); ?>
  </a>
</p>

<table class="table table-striped table-hover">
  <thead>
    <tr>
      <th><?php echo _('Name'); ?></th>
      <th><?php echo _('Conference'); ?></th>
      <th><?php echo _('Next fire'); ?></th>
      <th><?php echo _('Last fire'); ?></th>
      <th><?php echo _('Last status'); ?></th>
      <th><?php echo _('Enabled'); ?></th>
      <th class="text-right"><?php echo _('Actions'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($jobs)): ?>
      <tr>
        <td colspan="7" class="text-center text-muted">
          <?php echo _('No schedules yet. Click "Add new schedule" to create one.'); ?>
        </td>
      </tr>
    <?php else: foreach ($jobs as $job): ?>
      <tr>
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
          <?php else: ?>
            <br><small class="text-danger"><?php echo _('(missing)'); ?></small>
          <?php endif; ?>
        </td>
        <td><?php echo $fmt($job['next_fire_utc'] ?? null, $job['timezone'] ?? 'UTC'); ?></td>
        <td><?php echo $fmt($job['last_fire_utc'] ?? null, $job['timezone'] ?? 'UTC'); ?></td>
        <td>
          <?php if (!empty($job['last_status'])): ?>
            <span class="label label-<?php echo $statusLabel($job['last_status']); ?>">
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
          <a href="?display=conferenceschedules&amp;action=edit&amp;id=<?php echo (int) $job['id']; ?>"
             class="btn btn-sm btn-default" title="<?php echo _('Edit'); ?>">
            <i class="fa fa-pencil"></i>
          </a>
          <a href="?display=conferenceschedules&amp;action=fire&amp;id=<?php echo (int) $job['id']; ?>"
             class="btn btn-sm btn-warning"
             onclick="return confirm('<?php echo _('Fire this schedule now? Phones will ring immediately.'); ?>');"
             title="<?php echo _('Fire Now'); ?>">
            <i class="fa fa-play"></i> <?php echo _('Fire'); ?>
          </a>
          <a href="?display=conferenceschedules&amp;action=delete&amp;id=<?php echo (int) $job['id']; ?>"
             class="btn btn-sm btn-danger"
             onclick="return confirm('<?php echo _('Delete this schedule? This cannot be undone.'); ?>');"
             title="<?php echo _('Delete'); ?>">
            <i class="fa fa-trash"></i>
          </a>
        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>
