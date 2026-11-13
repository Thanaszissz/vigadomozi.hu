<?php

namespace Tests\Unit;

use App\Support\SeatKey;
use PHPUnit\Framework\TestCase;

class SeatKeyLabelTest extends TestCase
{
    public function test_label_matches_the_frontend_format(): void
    {
        $this->assertSame('Földszinti emelvény · 2.sor 08.szék', SeatKey::label('Földszinti emelvény|2-8'));
        $this->assertSame('Emelet · 1.sor 05.szék', SeatKey::label('Emelet|1-05'));
        $this->assertSame('A.sor 12.szék', SeatKey::label('A-12'));
        $this->assertSame('-', SeatKey::label('-'));
    }
}
