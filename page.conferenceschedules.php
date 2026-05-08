<?php

// SPDX-License-Identifier: Apache-2.0
//
// Page entry for ?display=conferenceschedules. The actual save/delete/fire
// dispatching happens in Conferenceschedules::doConfigPageInit (which runs
// before output starts so needreload() and request-rewriting are usable).

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

$cs     = \FreePBX::Conferenceschedules();
$action = $_REQUEST['action'] ?? '';
$id     = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : null;
$error  = $cs->getLastError();
$flash  = null;

if (!empty($_REQUEST['saved'])) {
    $flash = ['type' => 'success', 'msg' => _('Schedule saved.')];
} elseif (!empty($_REQUEST['deleted'])) {
    $flash = ['type' => 'success', 'msg' => _('Schedule deleted.')];
} elseif (!empty($_REQUEST['fired'])) {
    $flash = [
        'type' => 'info',
        'msg'  => sprintf(_('Schedule %d fired.'), (int) $_REQUEST['fired']),
    ];
}

$view = $_REQUEST['view'] ?? '';

if ($view === 'history') {
    $jobFilter = isset($_REQUEST['job_id']) && $_REQUEST['job_id'] !== ''
        ? (int) $_REQUEST['job_id'] : null;
    $rows = $cs->listHistory($jobFilter, 100);
    $jobs = $cs->listJobs();
    include __DIR__ . '/views/history.php';
} elseif ($action === 'add' || $action === 'edit') {
    // On save error, redisplay the form with the user's POST values rather
    // than re-reading from DB (so unsaved input isn't lost).
    if ($error && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $job = $_POST;
        if (!empty($job['id'])) {
            $job['id'] = (int) $job['id'];
        }
    } else {
        $job = $id ? $cs->getJob($id) : null;
    }

    if ($action === 'edit' && !$job) {
        echo '<div class="alert alert-danger">' . _('Schedule not found.') . '</div>';
        return;
    }

    $conferences = $cs->listConferences();
    $extensions  = $cs->listExtensions();
    include __DIR__ . '/views/job_form.php';
} else {
    $jobs = $cs->listJobs();
    include __DIR__ . '/views/jobs_list.php';
}
