<?php

// SPDX-License-Identifier: Apache-2.0
//
// Runs once on `fwconsole ma install conferenceschedules`. Creates schema
// and registers the per-minute tick cron entry. Idempotent: safe to re-run
// after a partial install (CREATE TABLE IF NOT EXISTS, Cronmanager dedupes).

global $db, $amp_conf;

$pdo = \FreePBX::Database();

// ---- conferenceschedules_jobs ----
$pdo->query(
    "CREATE TABLE IF NOT EXISTS `conferenceschedules_jobs` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(190) NOT NULL,
        `description` TEXT NULL,
        `conference_exten` VARCHAR(50) NOT NULL,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
        `next_fire_utc` DATETIME NULL,
        `last_fire_utc` DATETIME NULL,
        `owner_user_id` INT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_name` (`name`),
        KEY `idx_due` (`enabled`, `next_fire_utc`),
        KEY `idx_owner` (`owner_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Migration for installs that pre-date the owner_user_id column. MariaDB 10.0.2+
// supports IF NOT EXISTS on ALTER TABLE so this is idempotent and safe to re-run.
$pdo->query(
    "ALTER TABLE conferenceschedules_jobs
        ADD COLUMN IF NOT EXISTS owner_user_id INT UNSIGNED NULL,
        ADD KEY IF NOT EXISTS idx_owner (owner_user_id)"
);

// ---- conferenceschedules_schedules ----
$pdo->query(
    "CREATE TABLE IF NOT EXISTS `conferenceschedules_schedules` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `job_id` INT UNSIGNED NOT NULL,
        `type` ENUM('recurring','oneoff','cron') NOT NULL DEFAULT 'recurring',
        `cron_expr` VARCHAR(190) NULL,
        `start_dt` DATETIME NULL,
        `end_dt` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_job` (`job_id`),
        CONSTRAINT `fk_cs_sched_job` FOREIGN KEY (`job_id`)
            REFERENCES `conferenceschedules_jobs` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ---- conferenceschedules_participants ----
$pdo->query(
    "CREATE TABLE IF NOT EXISTS `conferenceschedules_participants` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `job_id` INT UNSIGNED NOT NULL,
        `kind` ENUM('extension','external') NOT NULL,
        `value` VARCHAR(190) NOT NULL,
        `display_name` VARCHAR(190) NULL,
        `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_job_sort` (`job_id`, `sort_order`),
        CONSTRAINT `fk_cs_part_job` FOREIGN KEY (`job_id`)
            REFERENCES `conferenceschedules_jobs` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ---- conferenceschedules_options ----
$pdo->query(
    "CREATE TABLE IF NOT EXISTS `conferenceschedules_options` (
        `job_id` INT UNSIGNED NOT NULL,
        `caller_id_name` VARCHAR(64) NULL,
        `caller_id_num` VARCHAR(32) NULL,
        `wait_time_sec` SMALLINT UNSIGNED NOT NULL DEFAULT 45,
        `intro_recording_id` INT UNSIGNED NULL,
        `concurrency_policy` ENUM('skip_if_active','force_new')
            NOT NULL DEFAULT 'skip_if_active',
        PRIMARY KEY (`job_id`),
        CONSTRAINT `fk_cs_opt_job` FOREIGN KEY (`job_id`)
            REFERENCES `conferenceschedules_jobs` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ---- conferenceschedules_history ----
$pdo->query(
    "CREATE TABLE IF NOT EXISTS `conferenceschedules_history` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `job_id` INT UNSIGNED NOT NULL,
        `fired_at_utc` DATETIME NOT NULL,
        `status` ENUM('success','partial','failed','skipped') NOT NULL,
        `participants_json` MEDIUMTEXT NULL,
        `error_text` TEXT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_job_fired` (`job_id`, `fired_at_utc`),
        CONSTRAINT `fk_cs_hist_job` FOREIGN KEY (`job_id`)
            REFERENCES `conferenceschedules_jobs` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ---- import shipped GPG signing key ----
// FreePBX flags any module not signed by Sangoma's GPG as "Unsigned" on the
// dashboard. We ship our own public key + a precomputed module.sig; importing
// the key into the asterisk user's keyring here makes the verifier accept the
// shipped signature. Idempotent — `gpg --import` of a known key is a no-op.
//
// install.php runs as the asterisk user (FreePBX drops privileges), so plain
// `gpg` targets ~asterisk/.gnupg. If gpg isn't available, we silently degrade
// to "Unsigned" (no worse than the pre-signing baseline).
$pubKey = __DIR__ . '/tools/signing-key.pub';
if (file_exists($pubKey) && function_exists('exec')) {
    @exec('gpg --import ' . escapeshellarg($pubKey) . ' 2>&1', $gpgOut, $gpgRet);
    if (function_exists('dbug')) {
        dbug('conferenceschedules: gpg --import returned ' . (int) $gpgRet);
    }
}

// ---- refresh UCP compiled asset bundle ----
// UCP concatenates every module's ucp/assets/js/global.js into a single
// /var/www/html/ucp/assets/js/compiled/modules/jsphp_<hash>.js. Without an
// explicit regeneration, our JS / CSS edits never reach the browser. Trigger
// the rebuild here so a freshly-installed module is usable in UCP immediately.
if (method_exists('\\FreePBX', 'Ucp')) {
    try {
        $ucp = \FreePBX::Ucp();
        if (method_exists($ucp, 'refreshAssets')) {
            $ucp->refreshAssets();
        }
    } catch (\Throwable $e) {
        if (function_exists('dbug')) {
            dbug('conferenceschedules: UCP refreshAssets failed: ' . $e->getMessage());
        }
    }
}

// ---- per-minute tick ----
// addLine() (vs add(array)) is required because Cron->add() rejects
// "* * * * *" all-wildcard schedules with "Probably a bug" — but we
// genuinely do want to fire every minute.
$tickLine = '* * * * * /usr/sbin/fwconsole conferenceschedules:tick > /dev/null 2>&1';
// Idempotent: strip any prior tick lines before adding.
foreach (\FreePBX::Cron()->getAll() as $existing) {
    if (strpos($existing, 'fwconsole conferenceschedules:tick') !== false) {
        \FreePBX::Cron()->removeLine($existing);
    }
}
\FreePBX::Cron()->addLine($tickLine);

return true;
