<?php

declare(strict_types=1);

namespace FreePBX\modules\Conferenceschedules\Tests;

use Cron\CronExpression;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPhpunitIsWired(): void
    {
        $this->assertTrue(true);
    }

    public function testCronExpressionDependencyIsAvailable(): void
    {
        $cron = new CronExpression('0 10 * * 2');
        $this->assertInstanceOf(CronExpression::class, $cron);
    }
}
