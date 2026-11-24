<?php

namespace Tests\Unit;

use App\Models\ScheduleDelay;
use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    public function test_delay_severity_bands(): void
    {
        $this->assertSame('minor', ScheduleDelay::severityFor(1));
        $this->assertSame('minor', ScheduleDelay::severityFor(9));
        $this->assertSame('major', ScheduleDelay::severityFor(10));
        $this->assertSame('major', ScheduleDelay::severityFor(29));
        $this->assertSame('critical', ScheduleDelay::severityFor(30));
        $this->assertSame('critical', ScheduleDelay::severityFor(120));
    }
}
