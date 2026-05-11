<?php

// SPDX-License-Identifier: Apache-2.0
//
// Manual end-to-end smoke test for the BMO data layer. Run on the FreePBX VM:
//   sudo -u asterisk php tests/fixtures/bmo_smoke.php
//
// Pre-req: at least one row in `meetme` (we insert one ourselves if needed
// and clean it up). This is NOT part of phpunit because it depends on the
// FreePBX framework being bootstrappable, which only the VM has.

require_once '/etc/freepbx.conf';

$fpbx = FreePBX::Create();
$db   = $fpbx->Database;
$mod  = $fpbx->Conferenceschedules;

$ok = 0;
$fail = 0;
$assertions = [];
function ok(string $msg, bool $cond) {
    global $ok, $fail, $assertions;
    if ($cond) { $ok++; $assertions[] = "PASS  $msg"; }
    else       { $fail++; $assertions[] = "FAIL  $msg"; }
}

// --- fixtures ----------------------------------------------------------------
$confExten = '99001';
// Idempotent setup: wipe any stale state from prior runs that may have
// crashed before reaching their teardown (e.g. partial schema migrations).
$db->prepare("DELETE FROM conferenceschedules_jobs WHERE name LIKE 'BMO %'")->execute();
$db->prepare("DELETE FROM meetme WHERE exten = :e")->execute([':e' => $confExten]);
$db->prepare(
    "INSERT INTO meetme (exten, description, userpin, adminpin, options, music, users, language, timeout)
     VALUES (:e, :d, '', '', '', 'default', 0, 'en', 21600)"
)->execute([':e' => $confExten, ':d' => 'BMO smoke test conf']);

// --- listConferences ---------------------------------------------------------
$confs = $mod->listConferences();
ok('listConferences includes our test room', (bool) array_filter(
    $confs,
    fn($c) => ($c['exten'] ?? '') === $confExten
));

// --- saveJob: valid create ---------------------------------------------------
$id = null;
try {
    $id = $mod->saveJob([
        'name'             => 'BMO Smoke Test',
        'description'      => 'created by tests/fixtures/bmo_smoke.php',
        'conference_exten' => $confExten,
        'enabled'          => 1,
        'timezone'         => 'America/Chicago',
        'options'          => [
            'caller_id_name'     => 'Smoke',
            'caller_id_num'      => '5550100',
            'wait_time_sec'      => 30,
            'concurrency_policy' => 'skip_if_active',
        ],
        'schedules'        => [
            ['type' => 'recurring', 'cron_expr' => '0 10 * * 2'],
        ],
        'participants'     => [
            ['kind' => 'extension', 'value' => '101', 'display_name' => 'Alice'],
            ['kind' => 'external',  'value' => '+15551234567', 'display_name' => 'Bob ext'],
        ],
    ]);
    ok('saveJob returns int id', is_int($id) && $id > 0);
} catch (Throwable $e) {
    ok('saveJob valid input did NOT throw: ' . $e->getMessage(), false);
}

// --- getJob roundtrip --------------------------------------------------------
$got = $mod->getJob($id);
ok('getJob returns array', is_array($got));
ok('getJob.name matches', ($got['name'] ?? null) === 'BMO Smoke Test');
ok('getJob.conference_exten matches', ($got['conference_exten'] ?? null) === $confExten);
ok('getJob.timezone matches', ($got['timezone'] ?? null) === 'America/Chicago');
ok('getJob.options.wait_time_sec=30', (int) ($got['options']['wait_time_sec'] ?? 0) === 30);
ok('getJob.schedules has 1 recurring', count($got['schedules'] ?? []) === 1
    && ($got['schedules'][0]['type'] ?? null) === 'recurring'
    && ($got['schedules'][0]['cron_expr'] ?? null) === '0 10 * * 2');
ok('getJob.participants has 2 sorted by sort_order',
    count($got['participants'] ?? []) === 2
    && ($got['participants'][0]['kind'] ?? null) === 'extension'
    && ($got['participants'][0]['value'] ?? null) === '101'
    && ($got['participants'][1]['kind'] ?? null) === 'external'
);

// --- listJobs ----------------------------------------------------------------
$list = $mod->listJobs();
ok('listJobs returns array', is_array($list));
ok('listJobs includes our job', (bool) array_filter($list, fn($r) => (int) $r['id'] === $id));
$row = current(array_filter($list, fn($r) => (int) $r['id'] === $id)) ?: [];
ok('listJobs row has conference_description from meetme JOIN',
    ($row['conference_description'] ?? null) === 'BMO smoke test conf');

// --- saveJob: update ---------------------------------------------------------
try {
    $mod->saveJob([
        'id'               => $id,
        'name'             => 'BMO Smoke Test (renamed)',
        'conference_exten' => $confExten,
        'enabled'          => 0,
        'timezone'         => 'UTC',
        'options'          => ['wait_time_sec' => 60, 'concurrency_policy' => 'force_new'],
        'schedules'        => [],
        'participants'     => [['kind' => 'extension', 'value' => '202']],
    ]);
    $upd = $mod->getJob($id);
    ok('saveJob update renames', ($upd['name'] ?? null) === 'BMO Smoke Test (renamed)');
    ok('saveJob update flips enabled', (int) ($upd['enabled'] ?? -1) === 0);
    ok('saveJob update changes options', (int) ($upd['options']['wait_time_sec'] ?? 0) === 60);
    ok('saveJob update replaces schedules', count($upd['schedules'] ?? []) === 0);
    ok('saveJob update replaces participants', count($upd['participants'] ?? []) === 1
        && ($upd['participants'][0]['value'] ?? null) === '202');
} catch (Throwable $e) {
    ok('saveJob update did NOT throw: ' . $e->getMessage(), false);
}

// --- saveJob: validation paths -----------------------------------------------
try {
    $mod->saveJob([
        'name'             => 'BMO Smoke Test (renamed)',  // duplicate
        'conference_exten' => $confExten,
    ]);
    ok('duplicate name rejected', false);
} catch (InvalidArgumentException $e) {
    ok('duplicate name rejected', strpos($e->getMessage(), 'already exists') !== false);
}

try {
    $mod->saveJob([
        'name'             => 'BMO Smoke Test bad tz',
        'conference_exten' => $confExten,
        'timezone'         => 'Mars/Olympus',
    ]);
    ok('bad timezone rejected', false);
} catch (InvalidArgumentException $e) {
    ok('bad timezone rejected', strpos($e->getMessage(), 'timezone') !== false
        || strpos($e->getMessage(), 'Timezone') !== false);
}

try {
    $mod->saveJob([
        'name'             => 'BMO Smoke Test bad cron',
        'conference_exten' => $confExten,
        'timezone'         => 'UTC',
        'schedules'        => [['type' => 'cron', 'cron_expr' => 'not-a-cron']],
    ]);
    ok('bad cron rejected', false);
} catch (InvalidArgumentException $e) {
    ok('bad cron rejected', strpos($e->getMessage(), 'cron') !== false);
}

try {
    $mod->saveJob([
        'name'             => 'BMO Smoke Test bad conf',
        'conference_exten' => 'NOPE',
        'timezone'         => 'UTC',
    ]);
    ok('non-existent conference rejected', false);
} catch (InvalidArgumentException $e) {
    ok('non-existent conference rejected', strpos($e->getMessage(), 'does not exist') !== false);
}

try {
    $mod->saveJob([
        'name'             => 'BMO Smoke Test bad ext',
        'conference_exten' => $confExten,
        'timezone'         => 'UTC',
        'participants'     => [['kind' => 'extension', 'value' => 'abc']],
    ]);
    ok('non-digit extension rejected', false);
} catch (InvalidArgumentException $e) {
    ok('non-digit extension rejected', strpos($e->getMessage(), 'digits only') !== false);
}

// --- owner_user_id scoping (UCP) ---------------------------------------------
// User A creates a schedule; User B should not be able to see / edit / delete /
// fire it. saveJob with $ownerUserId set must scope updates too.
$userA = 9001;
$userB = 9002;

$ownedId = $mod->saveJob([
    'name'             => 'BMO Owned-By-A',
    'conference_exten' => $confExten,
    'enabled'          => 1,
    'timezone'         => 'UTC',
    'schedules'        => [],
    'participants'     => [],
], $userA);
ok('saveJob with $ownerUserId returns id', is_int($ownedId) && $ownedId > 0);

$asA = $mod->getJob($ownedId, $userA);
ok('user A can read their own schedule', is_array($asA) && (int) ($asA['owner_user_id'] ?? 0) === $userA);

$asB = $mod->getJob($ownedId, $userB);
ok('user B cannot read user A\'s schedule', $asB === null);

$listA = $mod->listJobs($userA);
ok('listJobs scoped to user A includes owned schedule',
    (bool) array_filter($listA, fn($r) => (int) $r['id'] === $ownedId));

$listB = $mod->listJobs($userB);
ok('listJobs scoped to user B excludes user A\'s schedule',
    !array_filter($listB, fn($r) => (int) $r['id'] === $ownedId));

try {
    $mod->saveJob([
        'id'               => $ownedId,
        'name'             => 'BMO Owned-By-A (hijacked)',
        'conference_exten' => $confExten,
        'timezone'         => 'UTC',
    ], $userB);
    ok('user B cannot update user A\'s schedule', false);
} catch (InvalidArgumentException $e) {
    ok('user B cannot update user A\'s schedule', strpos($e->getMessage(), 'not owned') !== false);
}

ok('user B cannot delete user A\'s schedule', $mod->deleteJob($ownedId, $userB) === false);
ok('user A schedule still exists after B\'s delete attempt', $mod->getJob($ownedId, $userA) !== null);

ok('user A can delete own schedule', $mod->deleteJob($ownedId, $userA) === true);

// --- recomputeNextFire (called as part of saveJob) ---------------------------
$got2 = $mod->getJob($id);
// $got2 is the post-update job; we replaced schedules with [] in the update.
// So next_fire_utc should be NULL now.
ok('recomputeNextFire cleared next_fire on empty schedules', empty($got2['next_fire_utc']));

// Re-save with a recurring schedule and verify next_fire_utc gets populated.
try {
    $mod->saveJob([
        'id'               => $id,
        'name'             => 'BMO Smoke Test (renamed)',
        'conference_exten' => $confExten,
        'enabled'          => 1,
        'timezone'         => 'UTC',
        'options'          => ['wait_time_sec' => 45, 'concurrency_policy' => 'skip_if_active'],
        'schedules'        => [['type' => 'recurring', 'cron_expr' => '0 10 * * 2']],
        'participants'     => [['kind' => 'extension', 'value' => '101']],
    ]);
    $got3 = $mod->getJob($id);
    ok('recomputeNextFire set next_fire_utc for "0 10 * * 2"', !empty($got3['next_fire_utc']));
    if (!empty($got3['next_fire_utc'])) {
        $nextDt = new DateTime($got3['next_fire_utc'], new DateTimeZone('UTC'));
        ok('next_fire_utc is in the future', $nextDt > new DateTime('now', new DateTimeZone('UTC')));
        ok('next_fire_utc is a Tuesday at 10:00 UTC',
            $nextDt->format('w') === '2' && $nextDt->format('H:i') === '10:00');
    }
} catch (Throwable $e) {
    ok('saveJob with cron schedule did NOT throw: ' . $e->getMessage(), false);
}

// --- previewSchedule ---------------------------------------------------------
$preview = $mod->previewSchedule(
    ['frequency' => 'weekly', 'dows' => ['tue'], 'time' => '10:00'],
    'UTC',
    5
);
ok('previewSchedule weekly returned 5 times', count($preview['times'] ?? []) === 5);
ok('previewSchedule weekly all times contain 10:00',
    count(array_filter($preview['times'], fn($t) => strpos($t, '10:00') !== false)) === 5);
ok('previewSchedule weekly compiled to standard cron',
    ($preview['compiled']['cron_expr'] ?? null) === '0 10 * * 2');

// Monthly Nth weekday: "first Tuesday of every month at 10am" — uses our
// custom @nth: format and DateTime arithmetic, not extended cron.
$nth = $mod->previewSchedule(
    ['frequency' => 'monthly_ordinal', 'ordinal' => '1', 'dow' => 'tue', 'time' => '10:00'],
    'UTC',
    3
);
ok('previewSchedule monthly_ordinal compiled to @nth: format',
    ($nth['compiled']['cron_expr'] ?? '') === '@nth:1:2:10:00');
ok('previewSchedule monthly_ordinal returned 3 times', count($nth['times'] ?? []) === 3);
// All returned times should be Tuesdays at 10:00 in the first week of their month.
$allFirstTues = true;
foreach ($nth['times'] ?? [] as $t) {
    $date = explode(' ', $t)[0] ?? '';
    if (!$date) { $allFirstTues = false; break; }
    $dt = new DateTime($date);
    if ($dt->format('w') !== '2' || (int) $dt->format('j') > 7) {
        $allFirstTues = false;
        break;
    }
}
ok('previewSchedule first-Tuesday all match weekday=Tue and day-of-month <= 7', $allFirstTues);

// Quarterly
$q = $mod->previewSchedule(
    ['frequency' => 'quarterly_dom', 'dom' => 1, 'time' => '09:00'],
    'UTC',
    4
);
ok('previewSchedule quarterly compiled to multi-month cron',
    ($q['compiled']['cron_expr'] ?? '') === '0 9 1 1,4,7,10 *');

// --- AJAX endpoint -----------------------------------------------------------
$_REQUEST = [
    'command'  => 'preview-schedule',
    'schedule' => ['frequency' => 'weekly', 'dows' => ['mon', 'wed', 'fri'], 'time' => '14:30'],
    'tz'       => 'America/Chicago',
];
$ajaxSetting = null;
ok('ajaxRequest permits preview-schedule',
    $mod->ajaxRequest('preview-schedule', $ajaxSetting) === true);
ok('ajaxRequest denies unknown command',
    $mod->ajaxRequest('drop-tables', $ajaxSetting) === false);
$ajaxResp = $mod->ajaxHandler();
ok('ajaxHandler returns success', ($ajaxResp['status'] ?? false) === true);
ok('ajaxHandler returns 5 times', count($ajaxResp['times'] ?? []) === 5);
ok('ajaxHandler returns compiled cron',
    ($ajaxResp['compiled']['cron_expr'] ?? null) === '30 14 * * 1,3,5');

// Bad input via AJAX surfaces an error rather than throwing.
$_REQUEST['schedule'] = ['frequency' => 'weekly', 'dows' => ['nopeday'], 'time' => '10:00'];
$ajaxBad = $mod->ajaxHandler();
ok('ajaxHandler reports bad input as status:false', ($ajaxBad['status'] ?? null) === false);

// Form-mode saveJob: pass `schedule` (singular, frequency-based) instead of `schedules` (array).
$formId = $mod->saveJob([
    'name'             => 'BMO Form-Mode',
    'conference_exten' => $confExten,
    'enabled'          => 1,
    'timezone'         => 'UTC',
    'schedule'         => ['frequency' => 'monthly_ordinal', 'ordinal' => '1', 'dow' => 'tue', 'time' => '10:00'],
    'participants'     => [['kind' => 'extension', 'value' => '101']],
]);
$formJob = $mod->getJob($formId);
ok('form-mode saveJob compiled schedule[0] to @nth:',
    ($formJob['schedules'][0]['cron_expr'] ?? '') === '@nth:1:2:10:00');
ok('form-mode saveJob populated next_fire_utc for @nth: schedule',
    !empty($formJob['next_fire_utc']));
$mod->deleteJob($formId);

// --- fireJob (Step 8) --------------------------------------------------------
// Empty participants → 'failed' history row.
$emptyId = $mod->saveJob([
    'name'             => 'BMO Empty Job',
    'conference_exten' => $confExten,
    'enabled'          => 1,
    'timezone'         => 'UTC',
    'options'          => ['wait_time_sec' => 30, 'concurrency_policy' => 'force_new'],
    'schedules'        => [],
    'participants'     => [],
]);
ok('fireJob with no participants returns false', $mod->fireJob($emptyId) === false);
$hist = $mod->listHistory($emptyId);
ok('fireJob writes a history row for empty job', !empty($hist));
ok('empty job history status=failed',
    !empty($hist) && ($hist[0]['status'] ?? '') === 'failed');
ok('empty job history error_text mentions participants',
    !empty($hist) && strpos((string) $hist[0]['error_text'], 'articipants') !== false);

// last_fire_utc is updated regardless of outcome.
$emptyAfter = $mod->getJob($emptyId);
ok('fireJob bumps last_fire_utc even on failed', !empty($emptyAfter['last_fire_utc']));

// With participants — Originate against fake extensions; AMI ack vs. fail
// depends on Asterisk state, but a history row MUST be written.
$liveId = $mod->saveJob([
    'name'             => 'BMO Live Fire',
    'conference_exten' => $confExten,
    'enabled'          => 1,
    'timezone'         => 'UTC',
    'options'          => ['wait_time_sec' => 10, 'concurrency_policy' => 'force_new'],
    'schedules'        => [],
    'participants'     => [
        ['kind' => 'extension', 'value' => '99998', 'display_name' => 'Fake A'],
        ['kind' => 'extension', 'value' => '99999', 'display_name' => 'Fake B'],
    ],
]);
$mod->fireJob($liveId);  // do not assert return — depends on Asterisk
$hist2 = $mod->listHistory($liveId);
ok('fireJob with participants writes history', !empty($hist2));
$row = $hist2[0] ?? [];
ok('history status is one of success/partial/failed/skipped',
    in_array($row['status'] ?? '', ['success', 'partial', 'failed', 'skipped'], true));
$pj = json_decode($row['participants_json'] ?? '[]', true) ?: [];
ok('history participants_json is parseable array', is_array($pj));
ok('history participants_json has 2 legs', count($pj) === 2);
ok('each leg has a response field',
    is_array($pj) && count(array_filter($pj, fn($l) => isset($l['response']))) === count($pj));

// Per-participant skip-if-active filter — use the test-only activeOverride
// to simulate "99998 is already in the conference".
$skipId = $mod->saveJob([
    'name'             => 'BMO Skip-If-In-Room',
    'conference_exten' => $confExten,
    'enabled'          => 1,
    'timezone'         => 'UTC',
    'options'          => ['wait_time_sec' => 10, 'concurrency_policy' => 'skip_if_active'],
    'schedules'        => [],
    'participants'     => [
        ['kind' => 'extension', 'value' => '99998', 'display_name' => 'Already-in'],
        ['kind' => 'extension', 'value' => '99997', 'display_name' => 'Not-in'],
    ],
]);
$mod->activeOverride = ['99998'];  // pretend 99998 is in the room
$mod->fireJob($skipId);
$mod->activeOverride = null;

$skipHist = $mod->listHistory($skipId);
$skipRow = $skipHist[0] ?? [];
$skipLegs = json_decode($skipRow['participants_json'] ?? '[]', true) ?: [];

$skipped99998 = current(array_filter($skipLegs, fn($l) => ($l['value'] ?? null) === '99998'));
$dialed99997  = current(array_filter($skipLegs, fn($l) => ($l['value'] ?? null) === '99997'));

ok('skip_if_active: 99998 leg recorded as Skipped',
    is_array($skipped99998) && ($skipped99998['response'] ?? null) === 'Skipped');
ok('skip_if_active: 99998 leg has "Already in" message',
    is_array($skipped99998) && stripos((string) ($skipped99998['message'] ?? ''), 'Already in') !== false);
ok('skip_if_active: 99997 leg was actually dialed (not Skipped)',
    is_array($dialed99997) && ($dialed99997['response'] ?? null) !== 'Skipped');

// force_new bypasses the per-participant skip even when activeOverride is set.
$forceId = $mod->saveJob([
    'name'             => 'BMO Force-New',
    'conference_exten' => $confExten,
    'enabled'          => 1,
    'timezone'         => 'UTC',
    'options'          => ['wait_time_sec' => 10, 'concurrency_policy' => 'force_new'],
    'schedules'        => [],
    'participants'     => [
        ['kind' => 'extension', 'value' => '99998', 'display_name' => 'Already-in'],
    ],
]);
$mod->activeOverride = ['99998'];
$mod->fireJob($forceId);
$mod->activeOverride = null;

$forceLegs = json_decode($mod->listHistory($forceId)[0]['participants_json'] ?? '[]', true) ?: [];
ok('force_new: 99998 was dialed despite being in the room',
    !empty($forceLegs) && ($forceLegs[0]['response'] ?? null) !== 'Skipped');

// Status semantics: when ALL participants are skipped, history status is 'skipped'.
$allSkipId = $mod->saveJob([
    'name'             => 'BMO All-Skipped',
    'conference_exten' => $confExten,
    'enabled'          => 1,
    'timezone'         => 'UTC',
    'options'          => ['wait_time_sec' => 10, 'concurrency_policy' => 'skip_if_active'],
    'schedules'        => [],
    'participants'     => [
        ['kind' => 'extension', 'value' => '99996', 'display_name' => 'A'],
        ['kind' => 'extension', 'value' => '99995', 'display_name' => 'B'],
    ],
]);
$mod->activeOverride = ['99996', '99995'];
$mod->fireJob($allSkipId);
$mod->activeOverride = null;
ok('all-skipped fire: history status=skipped',
    ($mod->listHistory($allSkipId)[0]['status'] ?? '') === 'skipped');

// Cleanup
$mod->deleteJob($emptyId);
$mod->deleteJob($liveId);
$mod->deleteJob($skipId);
$mod->deleteJob($forceId);
$mod->deleteJob($allSkipId);

// --- processTick (Step 8) ----------------------------------------------------
// processTick should be a no-op when no enabled jobs are due.
$tickReport = $mod->processTick();
ok('processTick returns shape', isset($tickReport['count'], $tickReport['fired'], $tickReport['errors']));

// --- deleteJob ---------------------------------------------------------------
$deleted = $mod->deleteJob($id);
ok('deleteJob returns true', $deleted === true);
ok('deleteJob actually removed it', $mod->getJob($id) === null);

// FK CASCADE check: confirm child tables are empty for this id.
$opt = $db->prepare("SELECT COUNT(*) FROM conferenceschedules_options WHERE job_id = :i");
$opt->execute([':i' => $id]);
ok('FK CASCADE removed options row', (int) $opt->fetchColumn() === 0);

// --- teardown ----------------------------------------------------------------
$db->prepare("DELETE FROM meetme WHERE exten = :e")->execute([':e' => $confExten]);

// --- report ------------------------------------------------------------------
echo str_repeat('=', 60) . "\n";
echo "BMO smoke test\n";
echo str_repeat('=', 60) . "\n";
foreach ($assertions as $a) {
    echo $a, "\n";
}
echo str_repeat('-', 60) . "\n";
echo "$ok passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
