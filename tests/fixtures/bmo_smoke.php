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

// --- previewQuickRecurring ---------------------------------------------------
$preview = $mod->previewQuickRecurring(['tue'], '10:00', 'UTC', 5);
ok('previewQuickRecurring cron compiled correctly', $preview['cron'] === '0 10 * * 2');
ok('previewQuickRecurring returned 5 times', count($preview['times'] ?? []) === 5);
ok('previewQuickRecurring all times contain 10:00',
    count(array_filter($preview['times'], fn($t) => strpos($t, '10:00') !== false)) === 5);

// --- AJAX endpoint -----------------------------------------------------------
$_REQUEST = [
    'command' => 'preview-quick-recurring',
    'dows'    => ['mon', 'wed', 'fri'],
    'time'    => '14:30',
    'tz'      => 'America/Chicago',
];
$ajaxSetting = null;
ok('ajaxRequest permits preview-quick-recurring',
    $mod->ajaxRequest('preview-quick-recurring', $ajaxSetting) === true);
ok('ajaxRequest denies unknown command',
    $mod->ajaxRequest('drop-tables', $ajaxSetting) === false);
$ajaxResp = $mod->ajaxHandler();
ok('ajaxHandler returns success', ($ajaxResp['status'] ?? false) === true);
ok('ajaxHandler returns 5 times', count($ajaxResp['times'] ?? []) === 5);
ok('ajaxHandler returns compiled cron', ($ajaxResp['cron'] ?? null) === '30 14 * * 1,3,5');

// Bad input via AJAX surfaces an error rather than throwing.
$_REQUEST['dows'] = ['nopeday'];
$ajaxBad = $mod->ajaxHandler();
ok('ajaxHandler reports bad input as status:false', ($ajaxBad['status'] ?? null) === false);

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

// Cleanup
$mod->deleteJob($emptyId);
$mod->deleteJob($liveId);

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
