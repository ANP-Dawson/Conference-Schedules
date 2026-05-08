<?php

// SPDX-License-Identifier: Apache-2.0
//
// Runs on `fwconsole ma uninstall conferenceschedules`. Reverses install.php:
// removes the tick cron entry and drops all module tables in FK-safe order.

$pdo = \FreePBX::Database();

// Remove any tick cron lines (match by command — line format may vary).
foreach (\FreePBX::Cron()->getAll() as $existing) {
    if (strpos($existing, 'fwconsole conferenceschedules:tick') !== false) {
        \FreePBX::Cron()->removeLine($existing);
    }
}

// Drop children before parents to keep FK CASCADE happy on engines that
// validate constraints during DROP.
$tables = [
    'conferenceschedules_history',
    'conferenceschedules_options',
    'conferenceschedules_participants',
    'conferenceschedules_schedules',
    'conferenceschedules_jobs',
];

foreach ($tables as $table) {
    $pdo->query("DROP TABLE IF EXISTS `{$table}`");
}

return true;
