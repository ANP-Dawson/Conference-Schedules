<?php

declare(strict_types=1);

// SPDX-License-Identifier: Apache-2.0

namespace FreePBX\modules\Conferenceschedules;

use Cron\CronExpression;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pure (DB-free, framework-free) validators and normalizers for job input.
 *
 * Each `validateX` throws InvalidArgumentException with a translatable message
 * on failure and returns void on success. The exception messages are wrapped in
 * `_()` so they participate in gettext extraction even though this class never
 * touches the FreePBX framework directly.
 *
 * `compileQuickRecurringCron` and `assertValidJobShape` are higher-level helpers
 * used by Conferenceschedules::saveJob.
 */
final class Validators
{
    public const PARTICIPANT_KINDS = ['extension', 'external'];
    public const SCHEDULE_TYPES = ['recurring', 'oneoff', 'cron'];
    public const CONCURRENCY_POLICIES = ['skip_if_active', 'force_new'];
    public const HISTORY_STATUSES = ['success', 'partial', 'failed', 'skipped'];

    /** Frequency choices the form's "Schedule" tab presents. */
    public const FREQUENCIES = [
        'oneoff', 'daily', 'weekly', 'monthly_dom',
        'monthly_ordinal', 'quarterly_dom', 'custom_cron',
    ];
    public const ORDINALS = ['1', '2', '3', '4', 'L'];

    public const WAIT_TIME_MIN = 5;
    public const WAIT_TIME_MAX = 300;

    /** Day-of-week codes accepted by the Quick Recurring schedule editor. */
    public const DOW_CODES = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0];

    public static function validateName(string $name): void
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new InvalidArgumentException(_('Schedule name is required'));
        }
        if (mb_strlen($trimmed) > 190) {
            throw new InvalidArgumentException(_('Schedule name must be 190 characters or fewer'));
        }
    }

    public static function validateTimezone(string $tz): void
    {
        if ($tz === '') {
            throw new InvalidArgumentException(_('Timezone is required'));
        }
        try {
            new DateTimeZone($tz);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(sprintf(_('Invalid timezone: %s'), $tz));
        }
    }

    public static function validateConferenceExten(string $exten): void
    {
        $trimmed = trim($exten);
        if ($trimmed === '') {
            throw new InvalidArgumentException(_('Conference room is required'));
        }
        if (mb_strlen($trimmed) > 50) {
            throw new InvalidArgumentException(_('Conference room identifier must be 50 characters or fewer'));
        }
    }

    public static function validateCron(string $expr): void
    {
        $trimmed = trim($expr);
        if ($trimmed === '') {
            throw new InvalidArgumentException(_('Cron expression is required'));
        }
        if (!CronExpression::isValidExpression($trimmed)) {
            throw new InvalidArgumentException(sprintf(_('Invalid cron expression: %s'), $trimmed));
        }
    }

    public static function validateScheduleType(string $type): void
    {
        if (!in_array($type, self::SCHEDULE_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(_('Invalid schedule type: %s'), $type));
        }
    }

    public static function validateParticipantKind(string $kind): void
    {
        if (!in_array($kind, self::PARTICIPANT_KINDS, true)) {
            throw new InvalidArgumentException(sprintf(_('Invalid participant kind: %s'), $kind));
        }
    }

    /**
     * Participant value validation. Extensions must be digits-only; external
     * numbers may include digits and common dial-string characters (+, -,
     * space, parentheses, dot). The PBX outbound route is responsible for
     * stripping/normalizing — we just guard against obviously bad values.
     */
    public static function validateParticipantValue(string $value, string $kind): void
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException(_('Participant number is required'));
        }
        if (mb_strlen($trimmed) > 190) {
            throw new InvalidArgumentException(_('Participant number must be 190 characters or fewer'));
        }
        if ($kind === 'extension' && !preg_match('/^\d+$/', $trimmed)) {
            throw new InvalidArgumentException(
                sprintf(_('Extension "%s" must contain digits only'), $trimmed)
            );
        }
        if ($kind === 'external' && !preg_match('/^[\d+\-().\s]+$/', $trimmed)) {
            throw new InvalidArgumentException(
                sprintf(_('External number "%s" contains unsupported characters'), $trimmed)
            );
        }
    }

    public static function validateWaitTime(int $sec): void
    {
        if ($sec < self::WAIT_TIME_MIN || $sec > self::WAIT_TIME_MAX) {
            throw new InvalidArgumentException(
                sprintf(
                    _('Wait time must be between %d and %d seconds'),
                    self::WAIT_TIME_MIN,
                    self::WAIT_TIME_MAX
                )
            );
        }
    }

    public static function validateConcurrencyPolicy(string $policy): void
    {
        if (!in_array($policy, self::CONCURRENCY_POLICIES, true)) {
            throw new InvalidArgumentException(sprintf(_('Invalid concurrency policy: %s'), $policy));
        }
    }

    /**
     * Compile DOW checkbox + HH:MM time into a 5-field cron expression.
     *
     * @param array<int,string> $dows  e.g. ['mon','tue','wed'] (lowercase 3-letter codes)
     * @param string            $time  HH:MM (24h)
     */
    public static function compileQuickRecurringCron(array $dows, string $time): string
    {
        if ($dows === []) {
            throw new InvalidArgumentException(_('Pick at least one day of the week'));
        }
        $codes = [];
        foreach ($dows as $dow) {
            $key = strtolower(trim((string) $dow));
            if (!isset(self::DOW_CODES[$key])) {
                throw new InvalidArgumentException(sprintf(_('Unknown day-of-week: %s'), $dow));
            }
            $codes[] = self::DOW_CODES[$key];
        }
        sort($codes);
        $codes = array_values(array_unique($codes));

        [$h, $min] = self::parseTime($time);
        return sprintf('%d %d * * %s', $min, $h, implode(',', $codes));
    }

    /** Parse "HH:MM" into [hour, minute]. Throws on malformed input. */
    public static function parseTime(string $time): array
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            throw new InvalidArgumentException(sprintf(_('Invalid time (expected HH:MM): %s'), $time));
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            throw new InvalidArgumentException(sprintf(_('Time out of range (00:00–23:59): %s'), $time));
        }
        return [$h, $min];
    }

    /**
     * Compile a Schedule-tab form post into a single schedule row suitable
     * for INSERT into conferenceschedules_schedules.
     *
     * Returns ['type' => string, 'cron_expr' => string|null, 'start_dt' => string|null].
     *
     * For "Monthly (Nth weekday)" we use a non-cron storage format
     * `@nth:<ordinal>:<dow>:<HH>:<MM>` so we don't depend on extended cron
     * syntax (`#`/`L` for day-of-week) which the bundled mtdowling library on
     * FreePBX 17 doesn't support. Conferenceschedules::recomputeNextFire
     * recognizes this prefix and computes via DateTime arithmetic.
     *
     * @param array<string,mixed> $sched form values from the Schedule tab
     * @return array{type:string, cron_expr:string|null, start_dt:string|null}
     */
    public static function compileSchedule(array $sched): array
    {
        $freq = (string) ($sched['frequency'] ?? '');
        if (!in_array($freq, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException(sprintf(_('Unknown schedule frequency: %s'), $freq));
        }

        switch ($freq) {
            case 'oneoff':
                $dt = trim((string) ($sched['start_dt'] ?? ''));
                if ($dt === '') {
                    throw new InvalidArgumentException(_('One-off schedule requires a date and time'));
                }
                $parsed = date_create($dt);
                if (!$parsed) {
                    throw new InvalidArgumentException(sprintf(_('Invalid date/time: %s'), $dt));
                }
                return [
                    'type'      => 'oneoff',
                    'cron_expr' => null,
                    'start_dt'  => $parsed->format('Y-m-d H:i:s'),
                ];

            case 'daily':
                [$h, $m] = self::parseTime((string) ($sched['time'] ?? ''));
                return [
                    'type'      => 'recurring',
                    'cron_expr' => sprintf('%d %d * * *', $m, $h),
                    'start_dt'  => null,
                ];

            case 'weekly':
                $dows = (array) ($sched['dows'] ?? []);
                $time = (string) ($sched['time'] ?? '');
                return [
                    'type'      => 'recurring',
                    'cron_expr' => self::compileQuickRecurringCron($dows, $time),
                    'start_dt'  => null,
                ];

            case 'monthly_dom':
                $dom = (int) ($sched['dom'] ?? 0);
                if ($dom < 1 || $dom > 28) {
                    throw new InvalidArgumentException(
                        _('Day of month must be 1-28 (use 28 to safely target the end of every month)')
                    );
                }
                [$h, $m] = self::parseTime((string) ($sched['time'] ?? ''));
                return [
                    'type'      => 'recurring',
                    'cron_expr' => sprintf('%d %d %d * *', $m, $h, $dom),
                    'start_dt'  => null,
                ];

            case 'quarterly_dom':
                $dom = (int) ($sched['dom'] ?? 0);
                if ($dom < 1 || $dom > 28) {
                    throw new InvalidArgumentException(
                        _('Day of month must be 1-28')
                    );
                }
                [$h, $m] = self::parseTime((string) ($sched['time'] ?? ''));
                // Standard quarters: Jan / Apr / Jul / Oct.
                return [
                    'type'      => 'recurring',
                    'cron_expr' => sprintf('%d %d %d 1,4,7,10 *', $m, $h, $dom),
                    'start_dt'  => null,
                ];

            case 'monthly_ordinal':
                $ord = (string) ($sched['ordinal'] ?? '');
                if (!in_array($ord, self::ORDINALS, true)) {
                    throw new InvalidArgumentException(
                        sprintf(_('Invalid ordinal: %s (allowed: 1, 2, 3, 4, L)'), $ord)
                    );
                }
                $dowKey = strtolower(trim((string) ($sched['dow'] ?? '')));
                if (!isset(self::DOW_CODES[$dowKey])) {
                    throw new InvalidArgumentException(
                        sprintf(_('Unknown day-of-week: %s'), $dowKey)
                    );
                }
                [$h, $m] = self::parseTime((string) ($sched['time'] ?? ''));
                $expr = sprintf(
                    '@nth:%s:%d:%02d:%02d',
                    $ord,
                    self::DOW_CODES[$dowKey],
                    $h,
                    $m
                );
                return [
                    'type'      => 'recurring',
                    'cron_expr' => $expr,
                    'start_dt'  => null,
                ];

            case 'custom_cron':
                $expr = trim((string) ($sched['cron_expr'] ?? ''));
                self::validateCron($expr);
                return [
                    'type'      => 'cron',
                    'cron_expr' => $expr,
                    'start_dt'  => null,
                ];
        }

        // Should be unreachable due to FREQUENCIES check above.
        throw new InvalidArgumentException(_('Unhandled frequency'));
    }

    /**
     * Parse the multi-line output of `confbridge list <exten>` (Asterisk CLI
     * via AMI Command action) into a deduped list of identifiers — channel
     * suffix (extension or trunk leg) and trailing CallerID number where
     * present. Used by fireJob to decide which participants are already
     * present in the conference and should NOT be re-dialed.
     *
     * Robust to format drift across Asterisk versions: it ignores header /
     * separator / "No active" lines and pulls identifiers via regex rather
     * than column position.
     *
     * @return array<int,string> Deduplicated identifiers (digits where applicable).
     */
    public static function parseConfbridgeList(string $output): array
    {
        $ids = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Header / separator / "no active conferences" / END markers — skip.
            if (preg_match('/^=+\s*$/', $line)) {
                continue;
            }
            if (stripos($line, 'No active') === 0) {
                continue;
            }
            if (stripos($line, 'Channel') === 0) {
                continue;
            }
            if (stripos($line, 'Conference') === 0 && stripos($line, ' not found') !== false) {
                continue;
            }

            // Channel column: PJSIP/1001-00000001, SIP/trunk-..., Local/x@from-internal-..., DAHDI/...
            if (preg_match('#^(PJSIP|SIP|IAX2|Local|DAHDI)/([^\s@-]+)#i', $line, $m)) {
                $ids[] = $m[2];
            }

            // CallerID column — usually trailing on `confbridge list <exten>` output.
            // Heuristic: any 3+ digit number at line end (with optional + prefix).
            if (preg_match('/(?:^|\s)(\+?\d{3,})\s*$/', $line, $m)) {
                $ids[] = preg_replace('/\D/', '', $m[1]);
            }
        }
        $ids = array_filter($ids, function ($v) {
            return $v !== '';
        });
        return array_values(array_unique($ids));
    }

    /**
     * Is a participant value (extension or external number) plausibly already
     * present in the active-set returned by parseConfbridgeList? Compares
     * digit-only normalisations and accepts equality OR one being a suffix of
     * the other (handles `+1` country prefixes etc.). Errs toward over-matching
     * — better to skip a duplicate dial than to ring someone twice.
     */
    public static function isValueInActiveSet(string $value, array $activeIds): bool
    {
        $needle = preg_replace('/\D/', '', $value);
        if ($needle === '' || $needle === null) {
            return false;
        }
        foreach ($activeIds as $id) {
            $hay = preg_replace('/\D/', '', (string) $id);
            if ($hay === '' || $hay === null) {
                continue;
            }
            if ($hay === $needle) {
                return true;
            }
            $hayLen    = strlen($hay);
            $needleLen = strlen($needle);
            // Require the shorter string to be at least 4 digits before doing a
            // suffix match — guards against "01" matching every long number.
            $shorter = min($hayLen, $needleLen);
            if ($shorter < 4) {
                continue;
            }
            if ($hayLen > $needleLen && substr($hay, -$needleLen) === $needle) {
                return true;
            }
            if ($needleLen > $hayLen && substr($needle, -$hayLen) === $hay) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reverse: given a stored schedule row, derive the form fields used by
     * the Schedule tab so the form can pre-fill on edit.
     *
     * @param array<string,mixed> $row a row from conferenceschedules_schedules
     * @return array<string,mixed>
     */
    public static function explainSchedule(array $row): array
    {
        $type = (string) ($row['type'] ?? '');
        $expr = (string) ($row['cron_expr'] ?? '');

        if ($type === 'oneoff') {
            return ['frequency' => 'oneoff', 'start_dt' => (string) ($row['start_dt'] ?? '')];
        }

        if ($type === 'cron') {
            return ['frequency' => 'custom_cron', 'cron_expr' => $expr];
        }

        // type='recurring' — try to pattern-match the cron we generated.
        if (preg_match('/^@nth:([1-4L]):(\d):(\d{2}):(\d{2})$/', $expr, $m)) {
            $reverse = array_flip(self::DOW_CODES);
            return [
                'frequency' => 'monthly_ordinal',
                'ordinal'   => $m[1],
                'dow'       => $reverse[(int) $m[2]] ?? 'mon',
                'time'      => $m[3] . ':' . $m[4],
            ];
        }

        $parts = preg_split('/\s+/', $expr);
        if (count($parts) === 5) {
            [$min, $hour, $dom, $month, $dow] = $parts;
            $time = sprintf('%02d:%02d', (int) $hour, (int) $min);

            // Quarterly: dom is a single number, month is "1,4,7,10"
            if ($month === '1,4,7,10' && ctype_digit($dom)) {
                return [
                    'frequency' => 'quarterly_dom',
                    'dom'       => (int) $dom,
                    'time'      => $time,
                ];
            }

            // Monthly DOM: dom is single number, month and dow are *
            if ($dom !== '*' && $month === '*' && $dow === '*' && ctype_digit($dom)) {
                return [
                    'frequency' => 'monthly_dom',
                    'dom'       => (int) $dom,
                    'time'      => $time,
                ];
            }

            // Daily: everything but minute/hour is *
            if ($dom === '*' && $month === '*' && $dow === '*') {
                return ['frequency' => 'daily', 'time' => $time];
            }

            // Weekly: only dow specified (comma list)
            if ($dom === '*' && $month === '*' && $dow !== '*' && preg_match('/^[\d,]+$/', $dow)) {
                $reverse = array_flip(self::DOW_CODES);
                $dows = [];
                foreach (explode(',', $dow) as $d) {
                    if (isset($reverse[(int) $d])) {
                        $dows[] = $reverse[(int) $d];
                    }
                }
                return ['frequency' => 'weekly', 'dows' => $dows, 'time' => $time];
            }
        }

        // Anything else: treat as custom cron so the user can see/edit verbatim.
        return ['frequency' => 'custom_cron', 'cron_expr' => $expr];
    }
}
