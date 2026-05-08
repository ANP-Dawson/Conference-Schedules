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

    // --- compileSchedule ------------------------------------------------------

    public function testCompileScheduleDaily(): void
    {
        $r = Validators::compileSchedule(['frequency' => 'daily', 'time' => '08:30']);
        $this->assertSame('recurring', $r['type']);
        $this->assertSame('30 8 * * *', $r['cron_expr']);
        $this->assertNull($r['start_dt']);
    }

    public function testCompileScheduleWeekly(): void
    {
        $r = Validators::compileSchedule([
            'frequency' => 'weekly',
            'dows'      => ['mon', 'wed', 'fri'],
            'time'      => '09:00',
        ]);
        $this->assertSame('0 9 * * 1,3,5', $r['cron_expr']);
    }

    public function testCompileScheduleMonthlyDom(): void
    {
        $r = Validators::compileSchedule([
            'frequency' => 'monthly_dom',
            'dom'       => 15,
            'time'      => '10:00',
        ]);
        $this->assertSame('0 10 15 * *', $r['cron_expr']);
    }

    public function testCompileScheduleMonthlyDomRejectsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::compileSchedule([
            'frequency' => 'monthly_dom',
            'dom'       => 31,  // we cap at 28 to safely target every month
            'time'      => '10:00',
        ]);
    }

    public function testCompileScheduleQuarterlyDom(): void
    {
        $r = Validators::compileSchedule([
            'frequency' => 'quarterly_dom',
            'dom'       => 1,
            'time'      => '09:00',
        ]);
        $this->assertSame('0 9 1 1,4,7,10 *', $r['cron_expr']);
    }

    public function testCompileScheduleMonthlyOrdinal(): void
    {
        $r = Validators::compileSchedule([
            'frequency' => 'monthly_ordinal',
            'ordinal'   => '1',
            'dow'       => 'tue',
            'time'      => '10:00',
        ]);
        $this->assertSame('@nth:1:2:10:00', $r['cron_expr']);
    }

    public function testCompileScheduleMonthlyOrdinalLast(): void
    {
        $r = Validators::compileSchedule([
            'frequency' => 'monthly_ordinal',
            'ordinal'   => 'L',
            'dow'       => 'fri',
            'time'      => '14:30',
        ]);
        $this->assertSame('@nth:L:5:14:30', $r['cron_expr']);
    }

    public function testCompileScheduleMonthlyOrdinalRejectsBadOrdinal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::compileSchedule([
            'frequency' => 'monthly_ordinal',
            'ordinal'   => '5',  // only 1-4 and L
            'dow'       => 'tue',
            'time'      => '10:00',
        ]);
    }

    public function testCompileScheduleOneoff(): void
    {
        $r = Validators::compileSchedule([
            'frequency' => 'oneoff',
            'start_dt'  => '2026-12-25 09:00',
        ]);
        $this->assertSame('oneoff', $r['type']);
        $this->assertNull($r['cron_expr']);
        $this->assertSame('2026-12-25 09:00:00', $r['start_dt']);
    }

    public function testCompileScheduleCustomCron(): void
    {
        $r = Validators::compileSchedule([
            'frequency' => 'custom_cron',
            'cron_expr' => '*/15 9-17 * * 1-5',
        ]);
        $this->assertSame('cron', $r['type']);
        $this->assertSame('*/15 9-17 * * 1-5', $r['cron_expr']);
    }

    public function testCompileScheduleCustomCronRejectsMalformed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::compileSchedule(['frequency' => 'custom_cron', 'cron_expr' => 'nope']);
    }

    public function testCompileScheduleRejectsUnknownFrequency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validators::compileSchedule(['frequency' => 'fortnightly', 'time' => '10:00']);
    }

    // --- explainSchedule (round-trip) ----------------------------------------

    public function testExplainScheduleDaily(): void
    {
        $r = Validators::explainSchedule(['type' => 'recurring', 'cron_expr' => '30 8 * * *']);
        $this->assertSame('daily', $r['frequency']);
        $this->assertSame('08:30', $r['time']);
    }

    public function testExplainScheduleWeekly(): void
    {
        $r = Validators::explainSchedule(['type' => 'recurring', 'cron_expr' => '0 10 * * 1,3,5']);
        $this->assertSame('weekly', $r['frequency']);
        $this->assertSame(['mon', 'wed', 'fri'], $r['dows']);
        $this->assertSame('10:00', $r['time']);
    }

    public function testExplainScheduleMonthlyDom(): void
    {
        $r = Validators::explainSchedule(['type' => 'recurring', 'cron_expr' => '0 10 15 * *']);
        $this->assertSame('monthly_dom', $r['frequency']);
        $this->assertSame(15, $r['dom']);
        $this->assertSame('10:00', $r['time']);
    }

    public function testExplainScheduleQuarterlyDom(): void
    {
        $r = Validators::explainSchedule(['type' => 'recurring', 'cron_expr' => '0 9 1 1,4,7,10 *']);
        $this->assertSame('quarterly_dom', $r['frequency']);
        $this->assertSame(1, $r['dom']);
        $this->assertSame('09:00', $r['time']);
    }

    public function testExplainScheduleMonthlyOrdinal(): void
    {
        $r = Validators::explainSchedule(['type' => 'recurring', 'cron_expr' => '@nth:1:2:10:00']);
        $this->assertSame('monthly_ordinal', $r['frequency']);
        $this->assertSame('1', $r['ordinal']);
        $this->assertSame('tue', $r['dow']);
        $this->assertSame('10:00', $r['time']);
    }

    public function testExplainScheduleOneoff(): void
    {
        $r = Validators::explainSchedule(['type' => 'oneoff', 'start_dt' => '2026-12-25 09:00:00']);
        $this->assertSame('oneoff', $r['frequency']);
        $this->assertSame('2026-12-25 09:00:00', $r['start_dt']);
    }

    public function testExplainScheduleCustomCron(): void
    {
        $r = Validators::explainSchedule(['type' => 'cron', 'cron_expr' => '*/15 * * * *']);
        $this->assertSame('custom_cron', $r['frequency']);
        $this->assertSame('*/15 * * * *', $r['cron_expr']);
    }

    // --- parseConfbridgeList -------------------------------------------------

    public function testParseConfbridgeListExtractsExtensionsFromChannels(): void
    {
        $output = <<<TXT
Channel                       User Profile     Bridge Profile  Menu             CallerID
============================= ================ =============== ================ ===============
PJSIP/1001-00000001           default_user     default_bridge  default_menu     1001
PJSIP/1002-00000002           default_user     default_bridge  default_menu     1002
TXT;
        $ids = Validators::parseConfbridgeList($output);
        $this->assertContains('1001', $ids);
        $this->assertContains('1002', $ids);
    }

    public function testParseConfbridgeListIgnoresEmptyConference(): void
    {
        $this->assertSame([], Validators::parseConfbridgeList("No active conferences.\n"));
        $this->assertSame([], Validators::parseConfbridgeList("Conference '8001' not found.\n"));
        $this->assertSame([], Validators::parseConfbridgeList(""));
    }

    public function testParseConfbridgeListExtractsExternalCallerId(): void
    {
        // Trunk inbound where the channel doesn't carry the dialed number,
        // but CallerID column does.
        $output = "PJSIP/twilio-trunk-0000abc1   default_user     default_bridge  default_menu     +15551234567\n";
        $ids = Validators::parseConfbridgeList($output);
        $this->assertContains('15551234567', $ids);
    }

    // --- isValueInActiveSet --------------------------------------------------

    public function testIsValueInActiveSetExactMatch(): void
    {
        $this->assertTrue(Validators::isValueInActiveSet('1001', ['1001', '1002']));
    }

    public function testIsValueInActiveSetSuffixMatchHandlesCountryCode(): void
    {
        // External "+15551234567" should match active CallerID "5551234567"
        // (extension dialed without country code, displayed without).
        $this->assertTrue(Validators::isValueInActiveSet('+15551234567', ['5551234567']));
        // And vice versa.
        $this->assertTrue(Validators::isValueInActiveSet('5551234567', ['+15551234567']));
    }

    public function testIsValueInActiveSetRejectsShortMisleadingMatches(): void
    {
        // "01" must NOT match every long number; minimum 4 digits to suffix-match.
        $this->assertFalse(Validators::isValueInActiveSet('01', ['15551234501']));
    }

    public function testIsValueInActiveSetEmpty(): void
    {
        $this->assertFalse(Validators::isValueInActiveSet('1001', []));
        $this->assertFalse(Validators::isValueInActiveSet('', ['1001']));
    }

    public function testIsValueInActiveSetIgnoresNonDigits(): void
    {
        // Punctuation and letters in either side are stripped before compare.
        $this->assertTrue(Validators::isValueInActiveSet('+1 (555) 123-4567', ['15551234567']));
    }

    public function testCompileExplainRoundTrip(): void
    {
        $cases = [
            ['frequency' => 'daily', 'time' => '07:15'],
            ['frequency' => 'weekly', 'dows' => ['tue', 'thu'], 'time' => '15:00'],
            ['frequency' => 'monthly_dom', 'dom' => 5, 'time' => '11:30'],
            ['frequency' => 'quarterly_dom', 'dom' => 28, 'time' => '08:00'],
            ['frequency' => 'monthly_ordinal', 'ordinal' => '3', 'dow' => 'wed', 'time' => '12:00'],
            ['frequency' => 'monthly_ordinal', 'ordinal' => 'L', 'dow' => 'sun', 'time' => '20:00'],
        ];
        foreach ($cases as $input) {
            $compiled = Validators::compileSchedule($input);
            $row = ['type' => $compiled['type'], 'cron_expr' => $compiled['cron_expr']];
            $explained = Validators::explainSchedule($row);
            $this->assertSame(
                $input['frequency'],
                $explained['frequency'],
                'roundtrip frequency for ' . json_encode($input)
            );
        }
    }
}
