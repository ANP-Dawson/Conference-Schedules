<?php

// SPDX-License-Identifier: Apache-2.0
//
// UCP-side widget for Conference Schedules. Wraps the admin-side BMO methods
// with per-user scoping so a UCP user only sees and mutates their own
// schedules. FreePBX symlinks this directory into the UCP module tree on
// `fwconsole reload`.

namespace UCP\Modules;

use UCP\Modules as Modules;

#[\AllowDynamicProperties]
class Conferenceschedules extends Modules
{
    protected $module = 'Conferenceschedules';

    /** @var array|null UCP user record */
    private $user = null;

    /** @var int|false UCP user id (userman_users.id) */
    private $userId = false;

    public function __construct($Modules)
    {
        $this->Modules = $Modules;
        $this->user    = $this->UCP->User->getUser();
        $this->userId  = $this->user ? (int) $this->user['id'] : false;
    }

    public function getWidgetList()
    {
        return $this->getSimpleWidgetList();
    }

    public function getSimpleWidgetList()
    {
        return [
            'rawname' => 'conferenceschedules',
            'display' => _('Conference Schedules'),
            'icon'    => 'fa fa-calendar-check-o',
            'list'    => [
                [
                    'display' => _('My Conference Schedules'),
                    'id'      => 'main',
                ],
            ],
        ];
    }

    /**
     * Render the widget body — the table of the user's schedules, the modal
     * form, and the bootstrap JS hooks.
     */
    public function getWidgetDisplay($id)
    {
        if ($this->userId === false) {
            return [
                'title' => _('Conference Schedules'),
                'html'  => '<div class="alert alert-warning">'
                    . _('Sign in to manage your conference schedules.') . '</div>',
            ];
        }

        $bmo = $this->UCP->FreePBX->Conferenceschedules;

        $displayvars = [
            'jobs'        => $bmo->listJobs($this->userId),
            'conferences' => $bmo->listConferences(),
            'extensions'  => $bmo->listExtensions(),
            'history'     => $bmo->listHistory(null, 25, $this->userId),
            'userId'      => $this->userId,
        ];

        return [
            'title' => _('Conference Schedules'),
            'html'  => $this->load_view(__DIR__ . '/views/widget.php', $displayvars),
        ];
    }

    /**
     * Settings panel — empty for now. Future: per-user defaults (default tz,
     * default caller ID, etc.).
     */
    public function getSimpleWidgetSettingsDisplay($id)
    {
        return $this->getWidgetSettingsDisplay($id);
    }

    public function getWidgetSettingsDisplay($id)
    {
        return [];
    }

    /**
     * Polled state for the widget — refreshes the schedules list summary
     * without re-rendering the whole DOM. Returns the same shape as listJobs.
     */
    public function poll($data)
    {
        if ($this->userId === false) {
            return ['jobs' => []];
        }
        $bmo = $this->UCP->FreePBX->Conferenceschedules;
        return ['jobs' => $bmo->listJobs($this->userId)];
    }

    // =====================================================================
    //  AJAX: ucp/ajax.php?module=conferenceschedules&command=...
    // =====================================================================

    public function ajaxRequest($command, $settings)
    {
        if ($this->userId === false) {
            return false;
        }
        $allowed = [
            'list', 'get', 'save', 'delete', 'fire',
            'preview', 'history', 'extensions', 'conferences',
        ];
        return in_array($command, $allowed, true);
    }

    public function ajaxHandler()
    {
        if ($this->userId === false) {
            return ['status' => false, 'message' => _('Not signed in')];
        }

        $bmo     = $this->UCP->FreePBX->Conferenceschedules;
        $command = $_REQUEST['command'] ?? '';

        try {
            switch ($command) {
                case 'list':
                    return [
                        'status' => true,
                        'jobs'   => $bmo->listJobs($this->userId),
                    ];

                case 'get':
                    $id  = (int) ($_REQUEST['id'] ?? 0);
                    $job = $bmo->getJob($id, $this->userId);
                    if (!$job) {
                        return ['status' => false, 'message' => _('Schedule not found')];
                    }
                    return ['status' => true, 'job' => $job];

                case 'save':
                    $newId = $bmo->saveJob($_POST, $this->userId);
                    return [
                        'status' => true,
                        'id'     => $newId,
                        'job'    => $bmo->getJob($newId, $this->userId),
                    ];

                case 'delete':
                    $id = (int) ($_REQUEST['id'] ?? 0);
                    $ok = $bmo->deleteJob($id, $this->userId);
                    if (!$ok) {
                        return ['status' => false, 'message' => _('Schedule not found or not owned by you')];
                    }
                    return ['status' => true];

                case 'fire':
                    $id  = (int) ($_REQUEST['id'] ?? 0);
                    $job = $bmo->getJob($id, $this->userId);
                    if (!$job) {
                        return ['status' => false, 'message' => _('Schedule not found')];
                    }
                    $bmo->fireJob($id, $this->userId);
                    return ['status' => true];

                case 'preview':
                    $sched = isset($_REQUEST['schedule']) && is_array($_REQUEST['schedule'])
                        ? $_REQUEST['schedule'] : [];
                    $tz    = (string) ($_REQUEST['tz'] ?? 'UTC');
                    return ['status' => true] + $bmo->previewSchedule($sched, $tz, 5);

                case 'history':
                    $jobId = isset($_REQUEST['job_id']) && $_REQUEST['job_id'] !== ''
                        ? (int) $_REQUEST['job_id'] : null;
                    return [
                        'status'  => true,
                        'history' => $bmo->listHistory($jobId, 50, $this->userId),
                    ];

                case 'extensions':
                    return ['status' => true, 'extensions' => $bmo->listExtensions()];

                case 'conferences':
                    return ['status' => true, 'conferences' => $bmo->listConferences()];

                default:
                    return ['status' => false, 'message' => _('Unknown command')];
            }
        } catch (\InvalidArgumentException $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }
}
