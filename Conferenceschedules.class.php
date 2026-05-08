<?php

// SPDX-License-Identifier: Apache-2.0

namespace FreePBX\modules;

use BMO;
use FreePBX_Helpers;
use FreePBX\modules\Conferenceschedules\Validators;
use PDO;

// Composer autoload — required for our PSR-4 namespace (lib/Validators.php
// etc.). FreePBX core's BMO autoloader only resolves single-segment names like
// FreePBX\modules\Conferenceschedules and won't follow nested namespaces.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Conferenceschedules extends FreePBX_Helpers implements BMO
{
    /** @var \FreePBX */
    private $freepbx;

    /** @var PDO */
    private $db;

    /**
     * Test-only override for getActiveConferenceParticipants. When set,
     * fireJob uses this list instead of querying AMI. Lets the smoke test
     * exercise the per-participant skip path without needing a real
     * occupied conference room.
     *
     * @var array<int,string>|null
     */
    public $activeOverride = null;

    public function __construct($freepbx = null)
    {
        if ($freepbx === null) {
            throw new \Exception('Not given a FreePBX object');
        }
        $this->freepbx = $freepbx;
        $this->db      = $freepbx->Database;
    }

    public function install()
    {
        // Schema and cron registration are handled by install.php so that the
        // module is usable even before BMO autoload completes on first install.
    }

    public function uninstall()
    {
        // See install() — uninstall.php drops tables and removes the cron line.
    }

    /** @var string|null Last save error — surfaced to the form view on redisplay. */
    private $lastError = null;

    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Action dispatcher. Runs before page output starts so we can use
     * needreload() and let the page view render the resulting state.
     * The page.conferenceschedules.php view reads `_REQUEST['cs_action']`
     * (set here when we want to override the URL action).
     */
    public function doConfigPageInit($page)
    {
        if ($page !== 'conferenceschedules') {
            return;
        }
        $action = $_REQUEST['action'] ?? '';
        $id     = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
            try {
                $this->saveJob($_POST);
                needreload();
                // Redirect-after-POST: drop into the jobs list with a flash.
                $_REQUEST['action'] = '';
                unset($_REQUEST['id']);
                $_REQUEST['saved']  = 1;
            } catch (\InvalidArgumentException $e) {
                $this->lastError    = $e->getMessage();
                $_REQUEST['action'] = $id ? 'edit' : 'add';
            }
            return;
        }

        if ($action === 'delete' && $id) {
            $this->deleteJob($id);
            needreload();
            $_REQUEST['action']  = 'list';
            $_REQUEST['deleted'] = 1;
            return;
        }

        if ($action === 'fire' && $id) {
            // Phase 1: returns false (stub). Step 8 wires real Originate calls.
            $this->fireJob($id);
            $_REQUEST['action'] = 'list';
            $_REQUEST['fired']  = $id;
            return;
        }
    }

    /**
     * Opt into dialplan hook discovery (FreePBX\Hooks::updateBMOHooks scans for
     * this method). Return value is the priority — true means default (500).
     */
    public function myDialplanHooks()
    {
        return true;
    }

    public function doDialplanHook(&$ext, $engine, $priority)
    {
        if ($engine !== 'asterisk') {
            return;
        }

        $context = 'app-cs-bridge';
        $exten   = 's';

        // exten => s,1,NoOp(Conference Schedule bridge job=${CS_JOB_ID})
        $ext->add($context, $exten, '', new \ext_noop('Conference Schedule bridge job=${CS_JOB_ID}'));
        //  same => n,Answer()
        $ext->add($context, $exten, '', new \ext_answer());
        //  same => n,Wait(1)
        $ext->add($context, $exten, '', new \ext_wait(1));
        //  same => n,ExecIf($["${CS_INTRO}" != ""]?Playback(${CS_INTRO}))
        $ext->add(
            $context,
            $exten,
            '',
            new \ext_execif('$["${CS_INTRO}" != ""]', 'Playback', '${CS_INTRO}')
        );
        //  same => n,Goto(from-internal,${CS_CONF_EXT},1)
        // \ext_goto signature is (priority, extension, context).
        $ext->add($context, $exten, '', new \ext_goto('1', '${CS_CONF_EXT}', 'from-internal'));

        // exten => h,1,Hangup()
        $ext->add($context, 'h', '', new \ext_hangup());
    }

    // =====================================================================
    //  Data layer
    // =====================================================================

    /**
     * Conference rooms available for selection in the job form. Pulled directly
     * from the conferences module's `meetme` table so we don't depend on the
     * Conferences BMO being instantiable in every context.
     *
     * @return array<int,array{exten:string,description:string}>
     */
    public function listConferences()
    {
        $stmt = $this->db->prepare(
            "SELECT exten, description FROM meetme ORDER BY exten"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * All jobs joined with their conference description and most recent
     * history status — single query, suitable for the jobs list view.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listJobs()
    {
        $sql = "SELECT j.id, j.name, j.description, j.conference_exten,
                       m.description AS conference_description,
                       j.enabled, j.timezone, j.next_fire_utc, j.last_fire_utc,
                       (SELECT h.status FROM conferenceschedules_history h
                          WHERE h.job_id = j.id
                          ORDER BY h.fired_at_utc DESC LIMIT 1) AS last_status
                  FROM conferenceschedules_jobs j
                  LEFT JOIN meetme m ON m.exten = j.conference_exten
                 ORDER BY j.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hydrated job record with its options, schedules, and participants.
     *
     * @return array<string,mixed>|null
     */
    public function getJob($id)
    {
        $jobId = (int) $id;
        if ($jobId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT j.*, m.description AS conference_description
               FROM conferenceschedules_jobs j
               LEFT JOIN meetme m ON m.exten = j.conference_exten
              WHERE j.id = :id"
        );
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return null;
        }

        $optStmt = $this->db->prepare(
            "SELECT caller_id_name, caller_id_num, wait_time_sec,
                    intro_recording_id, concurrency_policy
               FROM conferenceschedules_options
              WHERE job_id = :j"
        );
        $optStmt->execute([':j' => $jobId]);
        $job['options'] = $optStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'caller_id_name'     => null,
            'caller_id_num'      => null,
            'wait_time_sec'      => 45,
            'intro_recording_id' => null,
            'concurrency_policy' => 'skip_if_active',
        ];

        $schStmt = $this->db->prepare(
            "SELECT id, type, cron_expr, start_dt, end_dt
               FROM conferenceschedules_schedules
              WHERE job_id = :j
              ORDER BY id"
        );
        $schStmt->execute([':j' => $jobId]);
        $job['schedules'] = $schStmt->fetchAll(PDO::FETCH_ASSOC);

        $partStmt = $this->db->prepare(
            "SELECT id, kind, value, display_name, sort_order
               FROM conferenceschedules_participants
              WHERE job_id = :j
              ORDER BY sort_order, id"
        );
        $partStmt->execute([':j' => $jobId]);
        $job['participants'] = $partStmt->fetchAll(PDO::FETCH_ASSOC);

        return $job;
    }

    /**
     * Validate and persist a job (insert or update) plus its options,
     * schedules, and participants in a single transaction. Returns the job id.
     *
     * @param array<string,mixed> $post
     * @return int
     * @throws \InvalidArgumentException on validation failure
     * @throws \Exception on DB failure (transaction rolled back)
     */
    public function saveJob(array $post)
    {
        // Form mode: compile $post['schedule'] (singular, frequency-based) into
        // a single schedules[] row. Programmatic callers (smoke test, etc.) can
        // still pass `schedules` directly and bypass this.
        if (empty($post['schedules']) && !empty($post['schedule']) && is_array($post['schedule'])) {
            $compiled = Validators::compileSchedule($post['schedule']);
            $post['schedules'] = [$compiled];
        }

        $id              = isset($post['id']) && $post['id'] !== '' ? (int) $post['id'] : null;
        $name            = trim((string) ($post['name'] ?? ''));
        $description     = (string) ($post['description'] ?? '');
        $conferenceExten = trim((string) ($post['conference_exten'] ?? ''));
        $enabled         = !empty($post['enabled']) ? 1 : 0;
        $timezone        = trim((string) ($post['timezone'] ?? 'UTC'));

        Validators::validateName($name);
        Validators::validateConferenceExten($conferenceExten);
        Validators::validateTimezone($timezone);

        // Soft FK check: conference must exist in the conferences module's table.
        $check = $this->db->prepare("SELECT 1 FROM meetme WHERE exten = :e LIMIT 1");
        $check->execute([':e' => $conferenceExten]);
        if (!$check->fetchColumn()) {
            throw new \InvalidArgumentException(
                sprintf(_('Conference room "%s" does not exist'), $conferenceExten)
            );
        }

        // Uniqueness on name (case-sensitive — the unique key uses utf8mb4_unicode_ci
        // collation which is case-insensitive, so SELECT below also matches CI).
        if ($id === null) {
            $dup = $this->db->prepare(
                "SELECT id FROM conferenceschedules_jobs WHERE name = :n LIMIT 1"
            );
            $dup->execute([':n' => $name]);
        } else {
            $dup = $this->db->prepare(
                "SELECT id FROM conferenceschedules_jobs WHERE name = :n AND id <> :id LIMIT 1"
            );
            $dup->execute([':n' => $name, ':id' => $id]);
        }
        if ($dup->fetchColumn()) {
            throw new \InvalidArgumentException(
                sprintf(_('A schedule named "%s" already exists'), $name)
            );
        }

        $opt               = is_array($post['options'] ?? null) ? $post['options'] : [];
        $waitTime          = isset($opt['wait_time_sec']) ? (int) $opt['wait_time_sec'] : 45;
        $concurrencyPolicy = (string) ($opt['concurrency_policy'] ?? 'skip_if_active');
        Validators::validateWaitTime($waitTime);
        Validators::validateConcurrencyPolicy($concurrencyPolicy);

        $schedules = is_array($post['schedules'] ?? null) ? $post['schedules'] : [];
        foreach ($schedules as $sched) {
            $type = (string) ($sched['type'] ?? '');
            Validators::validateScheduleType($type);
            if ($type === 'recurring' || $type === 'cron') {
                $expr = (string) ($sched['cron_expr'] ?? '');
                // @nth: is our internal "Nth weekday of month" format — already
                // validated by compileSchedule, but doesn't parse as cron.
                if (strncmp($expr, '@nth:', 5) !== 0) {
                    Validators::validateCron($expr);
                }
            }
        }

        $participants = is_array($post['participants'] ?? null) ? $post['participants'] : [];
        foreach ($participants as $p) {
            $kind = (string) ($p['kind'] ?? '');
            Validators::validateParticipantKind($kind);
            Validators::validateParticipantValue((string) ($p['value'] ?? ''), $kind);
        }

        $this->db->beginTransaction();
        try {
            if ($id === null) {
                $ins = $this->db->prepare(
                    "INSERT INTO conferenceschedules_jobs
                        (name, description, conference_exten, enabled, timezone)
                     VALUES (:n, :d, :e, :en, :tz)"
                );
                $ins->execute([
                    ':n'  => $name,
                    ':d'  => $description !== '' ? $description : null,
                    ':e'  => $conferenceExten,
                    ':en' => $enabled,
                    ':tz' => $timezone,
                ]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $upd = $this->db->prepare(
                    "UPDATE conferenceschedules_jobs
                        SET name = :n, description = :d, conference_exten = :e,
                            enabled = :en, timezone = :tz
                      WHERE id = :id"
                );
                $upd->execute([
                    ':n'  => $name,
                    ':d'  => $description !== '' ? $description : null,
                    ':e'  => $conferenceExten,
                    ':en' => $enabled,
                    ':tz' => $timezone,
                    ':id' => $id,
                ]);
            }

            // Options: upsert via INSERT ... ON DUPLICATE KEY UPDATE.
            $optStmt = $this->db->prepare(
                "INSERT INTO conferenceschedules_options
                    (job_id, caller_id_name, caller_id_num, wait_time_sec,
                     intro_recording_id, concurrency_policy)
                 VALUES (:j, :cn, :cnum, :wt, :ir, :cp)
                 ON DUPLICATE KEY UPDATE
                    caller_id_name     = VALUES(caller_id_name),
                    caller_id_num      = VALUES(caller_id_num),
                    wait_time_sec      = VALUES(wait_time_sec),
                    intro_recording_id = VALUES(intro_recording_id),
                    concurrency_policy = VALUES(concurrency_policy)"
            );
            $optStmt->execute([
                ':j'    => $id,
                ':cn'   => $opt['caller_id_name'] ?? null,
                ':cnum' => $opt['caller_id_num'] ?? null,
                ':wt'   => $waitTime,
                ':ir'   => isset($opt['intro_recording_id']) && $opt['intro_recording_id'] !== ''
                    ? (int) $opt['intro_recording_id'] : null,
                ':cp'   => $concurrencyPolicy,
            ]);

            // Schedules: simplest correct strategy is delete-then-insert.
            $this->db->prepare("DELETE FROM conferenceschedules_schedules WHERE job_id = :j")
                ->execute([':j' => $id]);
            $insSched = $this->db->prepare(
                "INSERT INTO conferenceschedules_schedules
                    (job_id, type, cron_expr, start_dt, end_dt)
                 VALUES (:j, :t, :c, :s, :e)"
            );
            foreach ($schedules as $sched) {
                $insSched->execute([
                    ':j' => $id,
                    ':t' => $sched['type'],
                    ':c' => isset($sched['cron_expr']) && $sched['cron_expr'] !== '' ? $sched['cron_expr'] : null,
                    ':s' => isset($sched['start_dt']) && $sched['start_dt'] !== '' ? $sched['start_dt'] : null,
                    ':e' => isset($sched['end_dt']) && $sched['end_dt'] !== '' ? $sched['end_dt'] : null,
                ]);
            }

            // Participants: delete-then-insert.
            $this->db->prepare("DELETE FROM conferenceschedules_participants WHERE job_id = :j")
                ->execute([':j' => $id]);
            $insPart = $this->db->prepare(
                "INSERT INTO conferenceschedules_participants
                    (job_id, kind, value, display_name, sort_order)
                 VALUES (:j, :k, :v, :d, :s)"
            );
            $sortOrder = 0;
            foreach ($participants as $p) {
                $insPart->execute([
                    ':j' => $id,
                    ':k' => $p['kind'],
                    ':v' => trim((string) $p['value']),
                    ':d' => isset($p['display_name']) && $p['display_name'] !== '' ? $p['display_name'] : null,
                    ':s' => isset($p['sort_order']) && $p['sort_order'] !== ''
                        ? (int) $p['sort_order'] : $sortOrder++,
                ]);
            }

            // Step 7 fills this in. Phase 1 stub: no-op (next_fire_utc stays NULL).
            $this->recomputeNextFire($id);

            $this->db->commit();
            return $id;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a job. FK ON DELETE CASCADE handles options/schedules/participants/history.
     */
    public function deleteJob($id)
    {
        $jobId = (int) $id;
        if ($jobId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM conferenceschedules_jobs WHERE id = :id");
        $stmt->execute([':id' => $jobId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Recompute next_fire_utc from the job's schedule rows. Cron expressions
     * are evaluated in the job's local timezone; the result is stored as UTC.
     *
     * Returns the next fire time as a UTC `Y-m-d H:i:s` string, or null when
     * the job has no future occurrences.
     */
    public function recomputeNextFire($id)
    {
        $jobId = (int) $id;
        if ($jobId <= 0) {
            return null;
        }

        $job = $this->getJob($jobId);
        if (!$job) {
            return null;
        }

        $tzName = !empty($job['timezone']) ? (string) $job['timezone'] : 'UTC';
        try {
            new \DateTimeZone($tzName);
        } catch (\Exception $e) {
            $tzName = 'UTC';
        }

        $candidates = [];
        $nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));

        foreach ($job['schedules'] as $sched) {
            try {
                $type = (string) ($sched['type'] ?? '');
                $expr = (string) ($sched['cron_expr'] ?? '');

                if (($type === 'recurring' || $type === 'cron') && $expr !== '') {
                    if (strncmp($expr, '@nth:', 5) === 0) {
                        $next = self::nextNthOccurrence(
                            $expr,
                            new \DateTime('now', new \DateTimeZone($tzName))
                        );
                    } else {
                        // Standard 5-field cron via the bundled CronExpression.
                        $cron = new \Cron\CronExpression($expr);
                        $next = $cron->getNextRunDate('now', 0, false, $tzName);
                    }
                    if ($next instanceof \DateTime) {
                        $next->setTimezone(new \DateTimeZone('UTC'));
                        if ($next > $nowUtc) {
                            $candidates[] = $next;
                        }
                    }
                } elseif ($type === 'oneoff' && !empty($sched['start_dt'])) {
                    // start_dt stored as a wall-clock string; interpret in job tz.
                    $next = new \DateTime((string) $sched['start_dt'], new \DateTimeZone($tzName));
                    $next->setTimezone(new \DateTimeZone('UTC'));
                    if ($next > $nowUtc) {
                        $candidates[] = $next;
                    }
                }
            } catch (\Exception $e) {
                // Skip a malformed schedule silently — recompute should not
                // break a job that has one bad row.
                if (function_exists('dbug')) {
                    dbug('conferenceschedules: skipping malformed schedule on job '
                         . $jobId . ': ' . $e->getMessage());
                }
            }
        }

        $upd = $this->db->prepare(
            "UPDATE conferenceschedules_jobs SET next_fire_utc = :n WHERE id = :id"
        );

        if (!$candidates) {
            $upd->execute([':n' => null, ':id' => $jobId]);
            return null;
        }

        usort($candidates, function ($a, $b) {
            return $a <=> $b;
        });
        $nextStr = $candidates[0]->format('Y-m-d H:i:s');
        $upd->execute([':n' => $nextStr, ':id' => $jobId]);
        return $nextStr;
    }

    /**
     * Compute the next $count fire times for a Schedule-tab form post. Used
     * by the AJAX preview. Returns formatted datetime strings in the supplied
     * timezone, plus the compiled schedule shape for transparency.
     *
     * @param array<string,mixed> $sched form values from Schedule tab
     * @return array{compiled:array,times:array<int,string>}
     */
    public function previewSchedule(array $sched, string $tz, int $count = 5)
    {
        $compiled = Validators::compileSchedule($sched);
        $tzObj    = new \DateTimeZone($tz);
        $times    = [];

        if ($compiled['type'] === 'oneoff') {
            $dt = new \DateTime((string) $compiled['start_dt'], $tzObj);
            $times[] = $dt->format('Y-m-d H:i') . ' ' . $tzObj->getName();
        } elseif (
            isset($compiled['cron_expr'])
            && strncmp((string) $compiled['cron_expr'], '@nth:', 5) === 0
        ) {
            $cursor = new \DateTime('now', $tzObj);
            for ($i = 0; $i < $count; $i++) {
                $next = self::nextNthOccurrence((string) $compiled['cron_expr'], $cursor);
                if (!$next instanceof \DateTime) {
                    break;
                }
                $times[] = $next->format('Y-m-d H:i') . ' ' . $tzObj->getName();
                $cursor = clone $next;
                $cursor->modify('+1 minute');
            }
        } else {
            $expr   = new \Cron\CronExpression((string) $compiled['cron_expr']);
            $cursor = 'now';
            for ($i = 0; $i < $count; $i++) {
                $dt = $expr->getNextRunDate($cursor, 0, false, $tz);
                $times[] = $dt->format('Y-m-d H:i') . ' ' . $tzObj->getName();
                $cursor = $dt->modify('+1 second');
            }
        }

        return ['compiled' => $compiled, 'times' => $times];
    }

    /**
     * Resolve a `@nth:<ordinal>:<dow>:<HH>:<MM>` schedule expression into the
     * next firing strictly after $after, in $after's timezone. Returns null if
     * we somehow can't find an occurrence within the next 24 months (which
     * shouldn't happen for valid input).
     *
     * @return \DateTime|null in $after's timezone
     */
    private static function nextNthOccurrence(string $expr, \DateTime $after)
    {
        if (!preg_match('/^@nth:([1-4L]):(\d):(\d{2}):(\d{2})$/', $expr, $m)) {
            return null;
        }
        $ord   = $m[1];
        $dow   = (int) $m[2];
        $hour  = (int) $m[3];
        $min   = (int) $m[4];
        $tz    = $after->getTimezone();
        $names = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $dowName = $names[$dow] ?? 'sunday';

        $cursor = clone $after;
        for ($i = 0; $i < 24; $i++) {
            $year     = (int) $cursor->format('Y');
            $month    = (int) $cursor->format('n');
            $monthStr = sprintf('%04d-%02d', $year, $month);

            if ($ord === 'L') {
                $dt = new \DateTime("last $dowName of $monthStr", $tz);
            } else {
                $dt = new \DateTime("first $dowName of $monthStr", $tz);
                $weeksToAdd = (int) $ord - 1;
                if ($weeksToAdd > 0) {
                    $dt->modify('+' . $weeksToAdd . ' weeks');
                    // 5th-week scenarios that fall outside the month — skip.
                    if ((int) $dt->format('n') !== $month) {
                        $cursor->modify('first day of next month');
                        $cursor->setTime(0, 0);
                        continue;
                    }
                }
            }
            $dt->setTime($hour, $min);
            if ($dt > $after) {
                return $dt;
            }
            $cursor->modify('first day of next month');
            $cursor->setTime(0, 0);
        }
        return null;
    }

    // =====================================================================
    //  AJAX endpoint: ajax.php?module=conferenceschedules&command=...
    // =====================================================================

    /**
     * Allow specific AJAX commands. Return true to permit, false to deny.
     */
    public function ajaxRequest($req, &$setting)
    {
        $allowed = ['preview-schedule'];
        return in_array($req, $allowed, true);
    }

    /**
     * Dispatch AJAX commands. The framework wraps the return value in a JSON
     * response keyed by `status` ("success" by default).
     */
    public function ajaxHandler()
    {
        $command = $_REQUEST['command'] ?? '';
        if ($command !== 'preview-schedule') {
            return ['status' => false, 'message' => 'Unknown command'];
        }

        try {
            $sched = isset($_REQUEST['schedule']) && is_array($_REQUEST['schedule'])
                ? $_REQUEST['schedule'] : [];
            $tz    = (string) ($_REQUEST['tz'] ?? 'UTC');
            return ['status' => true] + $this->previewSchedule($sched, $tz, 5);
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Internal extensions known to FreePBX, for the participant picker.
     *
     * @return array<int,array{extension:string, name:string}>
     */
    public function listExtensions()
    {
        $stmt = $this->db->prepare("SELECT extension, name FROM users ORDER BY extension");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fire a job: dispatch AMI Originate calls to every participant. Each
     * answered leg is dropped into the conference room via the app-cs-bridge
     * dialplan context. Writes a history row aggregating per-leg outcomes.
     *
     * Returns true if at least one leg's AMI Originate request succeeded.
     */
    public function fireJob($id)
    {
        $jobId = (int) $id;
        $job = $this->getJob($jobId);
        if (!$job) {
            return false;
        }

        // last_fire_utc is updated regardless of outcome.
        $this->db->prepare(
            "UPDATE conferenceschedules_jobs SET last_fire_utc = UTC_TIMESTAMP() WHERE id = :id"
        )->execute([':id' => $jobId]);

        $opt           = is_array($job['options'] ?? null) ? $job['options'] : [];
        $policy        = (string) ($opt['concurrency_policy'] ?? 'skip_if_active');
        $confExten     = (string) ($job['conference_exten'] ?? '');

        // Per-participant filter: under skip_if_active (the default), don't
        // dial anyone who is already in the conference room. force_new bypasses
        // this and rings every participant. The active set is queried via AMI
        // `Command: confbridge list <exten>` and parsed in pure PHP (testable).
        $activeIds = [];
        if ($policy === 'skip_if_active' && $confExten !== '') {
            $activeIds = $this->activeOverride !== null
                ? $this->activeOverride
                : $this->getActiveConferenceParticipants($confExten);
        }

        $participants = is_array($job['participants'] ?? null) ? $job['participants'] : [];
        if (!$participants) {
            $this->writeHistory($jobId, 'failed', [], _('No participants configured'));
            $this->recomputeNextFire($jobId);
            return false;
        }

        $astman = $this->freepbx->astman ?? null;
        if (!$astman) {
            $this->writeHistory($jobId, 'failed', [], _('AMI (astman) not available'));
            $this->recomputeNextFire($jobId);
            return false;
        }

        // ---- Build common Originate parameters --------------------------
        $waitMs = max(1000, (int) ($opt['wait_time_sec'] ?? 45) * 1000);
        $cidName = trim((string) ($opt['caller_id_name'] ?? ''));
        $cidNum  = trim((string) ($opt['caller_id_num']  ?? ''));
        $callerid = '';
        if ($cidName !== '' || $cidNum !== '') {
            $callerid = ($cidName !== '' ? '"' . $cidName . '"' : '')
                . ($cidNum !== '' ? ($cidName !== '' ? ' ' : '') . '<' . $cidNum . '>' : '');
        }

        // Resolve intro recording filename (without extension — Playback wants base).
        $intro = '';
        $introId = !empty($opt['intro_recording_id']) ? (int) $opt['intro_recording_id'] : 0;
        if ($introId > 0) {
            try {
                $rec = $this->freepbx->Recordings->getRecordingsById($introId);
                if (!empty($rec['filename'])) {
                    $intro = preg_replace('/\.[^.]+$/', '', (string) $rec['filename']);
                }
            } catch (\Throwable $e) {
                // Recordings module not installed or recording deleted — fire
                // without an intro rather than failing the whole job.
                if (function_exists('dbug')) {
                    dbug('conferenceschedules: intro recording lookup failed: ' . $e->getMessage());
                }
            }
        }

        // ---- Per-leg Originate ------------------------------------------
        $results = [];
        foreach ($participants as $p) {
            $value   = trim((string) ($p['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $channel = "Local/{$value}@from-internal/n";

            $legOutcome = [
                'kind'         => $p['kind']         ?? null,
                'value'        => $value,
                'display_name' => $p['display_name'] ?? null,
                'channel'      => $channel,
            ];

            // Skip-if-active: don't ring participants already in the room.
            if ($activeIds && Validators::isValueInActiveSet($value, $activeIds)) {
                $legOutcome['response'] = 'Skipped';
                $legOutcome['message']  = _('Already in conference');
                $results[] = $legOutcome;
                continue;
            }

            // AMI Variable joins multiple key=value pairs with literal commas;
            // recipient-side Asterisk splits on comma so values cannot contain
            // commas. Our variables don't.
            $varStr = sprintf(
                'CS_JOB_ID=%d,CS_INTRO=%s,CS_CONF_EXT=%s',
                $jobId,
                $intro,
                $confExten
            );

            $params = [
                'Channel'  => $channel,
                'Context'  => 'app-cs-bridge',
                'Exten'    => 's',
                'Priority' => 1,
                'Timeout'  => $waitMs,
                'Variable' => $varStr,
                'Async'    => 'true',
            ];
            if ($callerid !== '') {
                $params['CallerID'] = $callerid;
            }

            try {
                $resp = $astman->Originate($params);
                $legOutcome['response'] = (string) ($resp['Response'] ?? '?');
                $legOutcome['message']  = $resp['Message'] ?? null;
            } catch (\Throwable $e) {
                $legOutcome['response'] = 'Error';
                $legOutcome['message']  = $e->getMessage();
            }
            $results[] = $legOutcome;
        }

        // Status semantics with three leg outcomes (Success / Skipped / Error):
        //   - all skipped (everyone already in)             → 'skipped'
        //   - success + skipped, no errors                  → 'success'
        //   - some success, no skips                        → 'success' (all-success)
        //   - mix of success and error                      → 'partial'
        //   - only errors                                   → 'failed'
        $total   = count($results);
        $success = 0;
        $skipped = 0;
        $error   = 0;
        foreach ($results as $r) {
            $resp = $r['response'] ?? '';
            if ($resp === 'Success') {
                $success++;
            } elseif ($resp === 'Skipped') {
                $skipped++;
            } else {
                $error++;
            }
        }
        if ($total === 0) {
            $status = 'failed';
        } elseif ($skipped === $total) {
            $status = 'skipped';
        } elseif ($error === 0) {
            $status = 'success';
        } elseif ($success === 0 && $skipped === 0) {
            $status = 'failed';
        } else {
            $status = 'partial';
        }

        $this->writeHistory($jobId, $status, $results, null);
        $this->recomputeNextFire($jobId);

        return $status !== 'failed';
    }

    /**
     * Query Asterisk via AMI for the current members of the named conference
     * room and return a list of identifiers (channel suffixes / CallerID
     * numbers). Returns [] on any failure — the safe default is "we don't
     * know who's in there" → don't filter anyone out.
     *
     * @return array<int,string>
     */
    private function getActiveConferenceParticipants(string $exten): array
    {
        $astman = $this->freepbx->astman ?? null;
        if (!$astman || $exten === '') {
            return [];
        }
        // Defensive: only allow alnum/underscore/dash in the exten we shell
        // through to the manager, even though the field has its own validation.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $exten)) {
            return [];
        }

        try {
            $resp = $astman->send_request('Command', ['Command' => 'confbridge list ' . $exten]);
        } catch (\Throwable $e) {
            return [];
        }

        // phpasmanager's send_request returns the response with the multi-line
        // CLI output collected under different keys depending on version.
        $output = '';
        foreach (['data', 'Output', 'output'] as $key) {
            if (!empty($resp[$key]) && is_string($resp[$key])) {
                $output = $resp[$key];
                break;
            }
        }
        if ($output === '') {
            return [];
        }

        return Validators::parseConfbridgeList($output);
    }

    /**
     * Append a row to conferenceschedules_history.
     *
     * @param array<int,array<string,mixed>> $participants
     */
    private function writeHistory(int $jobId, string $status, array $participants, ?string $error)
    {
        $stmt = $this->db->prepare(
            "INSERT INTO conferenceschedules_history
                (job_id, fired_at_utc, status, participants_json, error_text)
             VALUES (:j, UTC_TIMESTAMP(), :s, :p, :e)"
        );
        $stmt->execute([
            ':j' => $jobId,
            ':s' => $status,
            ':p' => json_encode($participants, JSON_UNESCAPED_SLASHES) ?: '[]',
            ':e' => $error,
        ]);
    }

    /**
     * Cron tick entry point: fire every job whose next_fire_utc has elapsed.
     * Per-job exceptions are caught and recorded as failed history rows so
     * one bad job cannot stop the loop.
     *
     * @return array{count:int, fired:int, errors:array<int,array{job_id:int,error:string}>}
     */
    public function processTick()
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM conferenceschedules_jobs
             WHERE enabled = 1
               AND next_fire_utc IS NOT NULL
               AND next_fire_utc <= UTC_TIMESTAMP()"
        );
        $stmt->execute();
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);

        $report = ['count' => count($ids), 'fired' => 0, 'errors' => []];
        foreach ($ids as $jobId) {
            $jobId = (int) $jobId;
            try {
                if ($this->fireJob($jobId)) {
                    $report['fired']++;
                }
            } catch (\Throwable $e) {
                $report['errors'][] = ['job_id' => $jobId, 'error' => $e->getMessage()];
                try {
                    $this->writeHistory($jobId, 'failed', [], $e->getMessage());
                    $this->recomputeNextFire($jobId);
                } catch (\Throwable $inner) {
                    // Last-resort: don't let history write itself break the loop.
                    if (function_exists('dbug')) {
                        dbug('conferenceschedules: writeHistory failed: ' . $inner->getMessage());
                    }
                }
            }
        }
        return $report;
    }

    /**
     * Recent firings for the history view, optionally filtered by job.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listHistory($jobId = null, $limit = 100)
    {
        $sql = "SELECT h.id, h.job_id, j.name AS job_name, h.fired_at_utc,
                       h.status, h.participants_json, h.error_text
                  FROM conferenceschedules_history h
                  LEFT JOIN conferenceschedules_jobs j ON j.id = h.job_id";
        $params = [];
        if ($jobId !== null) {
            $sql .= " WHERE h.job_id = :j";
            $params[':j'] = (int) $jobId;
        }
        $sql .= " ORDER BY h.fired_at_utc DESC LIMIT " . max(1, min((int) $limit, 1000));

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
