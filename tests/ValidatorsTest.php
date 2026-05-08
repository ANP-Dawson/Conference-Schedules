<?php

declare(strict_types=1);

namespace FreePBX\modules\Conferenceschedules\Tests;

use FreePBX\modules\Conferenceschedules\Validators;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure validators. No DB, no FreePBX framework.
 * The gettext shim `_()` is defined in tests/bootstrap.php.
 */
final class ValidatorsTest extends TestCase
{
    // --- name -----------------------------------------------------------------

    public function testValidateNameAcceptsNormal(): void
    {
        $this->expectNotToPerformAssertions();
        Validators::validateName('Weekly Status');
    }

    public function testValidateNameRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateName('   ');
    }

    public function testValidateNameRejectsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateName(str_repeat('a', 191));
    }

    // --- timezone -------------------------------------------------------------

    public function testValidateTimezoneAcceptsCommonIana(): void
    {
        $this->expectNotToPerformAssertions();
        Validators::validateTimezone('America/Chicago');
        Validators::validateTimezone('UTC');
        Validators::validateTimezone('Europe/London');
    }

    public function testValidateTimezoneRejectsBadString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateTimezone('Mars/Olympus_Mons');
    }

    // --- cron -----------------------------------------------------------------

    public function testValidateCronAcceptsValid(): void
    {
        $this->expectNotToPerformAssertions();
        Validators::validateCron('0 10 * * 2');
        Validators::validateCron('*/5 * * * *');
        Validators::validateCron('0 0 1 * *');
    }

    public function testValidateCronRejectsMalformed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateCron('not a cron');
    }

    public function testValidateCronRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateCron('');
    }

    // --- participant kind/value ----------------------------------------------

    public function testValidateParticipantKind(): void
    {
        $this->expectNotToPerformAssertions();
        Validators::validateParticipantKind('extension');
        Validators::validateParticipantKind('external');
    }

    public function testValidateParticipantKindRejectsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateParticipantKind('group');
    }

    public function testValidateParticipantValueExtensionOnlyDigits(): void
    {
        $this->expectNotToPerformAssertions();
        Validators::validateParticipantValue('1001', 'extension');
    }

    public function testValidateParticipantValueExtensionRejectsNonDigit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateParticipantValue('1001a', 'extension');
    }

    public function testValidateParticipantValueExternalAcceptsCommonChars(): void
    {
        $this->expectNotToPerformAssertions();
        Validators::validateParticipantValue('+1 (555) 123-4567', 'external');
        Validators::validateParticipantValue('15551234567', 'external');
    }

    public function testValidateParticipantValueExternalRejectsLetters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateParticipantValue('1-800-FLOWERS', 'external');
    }

    // --- wait time ------------------------------------------------------------

    public function testValidateWaitTimeBounds(): void
    {
        $this->expectNotToPerformAssertions();
        Validators::validateWaitTime(Validators::WAIT_TIME_MIN);
        Validators::validateWaitTime(45);
        Validators::validateWaitTime(Validators::WAIT_TIME_MAX);
    }

    public function testValidateWaitTimeRejectsTooLow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateWaitTime(Validators::WAIT_TIME_MIN - 1);
    }

    public function testValidateWaitTimeRejectsTooHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateWaitTime(Validators::WAIT_TIME_MAX + 1);
    }

    // --- concurrency policy ---------------------------------------------------

    public function testValidateConcurrencyPolicy(): void
    {
        $this->expectNotToPerformAssertions();
        Validators::validateConcurrencyPolicy('skip_if_active');
        Validators::validateConcurrencyPolicy('force_new');
    }

    public function testValidateConcurrencyPolicyRejectsUnknown(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::validateConcurrencyPolicy('queue');
    }

    // --- compile quick recurring cron ----------------------------------------

    public function testCompileQuickRecurringCronTuesday10AM(): void
    {
        $this->assertSame(
            '0 10 * * 2',
            Validators::compileQuickRecurringCron(['tue'], '10:00')
        );
    }

    public function testCompileQuickRecurringCronMultipleDays(): void
    {
        $this->assertSame(
            '30 9 * * 1,3,5',
            Validators::compileQuickRecurringCron(['mon', 'wed', 'fri'], '9:30')
        );
    }

    public function testCompileQuickRecurringCronDeduplicatesAndSorts(): void
    {
        $this->assertSame(
            '0 14 * * 1,2',
            Validators::compileQuickRecurringCron(['tue', 'mon', 'tue'], '14:00')
        );
    }

    public function testCompileQuickRecurringCronRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::compileQuickRecurringCron([], '10:00');
    }

    public function testCompileQuickRecurringCronRejectsBadDow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::compileQuickRecurringCron(['funday'], '10:00');
    }

    public function testCompileQuickRecurringCronRejectsBadTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::compileQuickRecurringCron(['mon'], '25:00');
    }
}
