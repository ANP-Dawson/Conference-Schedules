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

    public const WAIT_TIME_MIN = 5;
    public const WAIT_TIME_MAX = 300;

    /** Day-of-week codes accepted by the Quick Recurring schedule editor. */
    public const DOW_CODES = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0];

    public static function validateName(string $name): void
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new InvalidArgumentException(_('Job name is required'));
        }
        if (mb_strlen($trimmed) > 190) {
            throw new InvalidArgumentException(_('Job name must be 190 characters or fewer'));
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

        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            throw new InvalidArgumentException(sprintf(_('Invalid time (expected HH:MM): %s'), $time));
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            throw new InvalidArgumentException(sprintf(_('Time out of range (00:00–23:59): %s'), $time));
        }

        return sprintf('%d %d * * %s', $min, $h, implode(',', $codes));
    }
}
