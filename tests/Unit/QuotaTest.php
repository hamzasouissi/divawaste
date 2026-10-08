<?php

namespace Tests\Unit;

use App\Modules\Common\Support\Quota;
use PHPUnit\Framework\TestCase;

class QuotaTest extends TestCase
{
    public function test_mixed_contents_add_their_shares(): void
    {
        // 20 bales out of 40 and 30 bags out of 100: half the room plus 30 % of it.
        $usage = Quota::usage(null, ['bale' => 40, 'bag' => 100], '0', ['bale' => 20, 'bag' => 30]);

        $this->assertSame(80.0, $usage['pct']);
        $this->assertNull($usage['weight_pct']);
        $this->assertSame(['bale' => 8, 'bag' => 20], $usage['remaining']['counts']);
        $this->assertSame(0, $usage['uncounted']);
    }

    public function test_the_higher_of_weight_and_count_is_the_one_that_counts(): void
    {
        $usage = Quota::usage('1000', ['bale' => 40], '900.000', ['bale' => 10]);

        $this->assertSame(25.0, $usage['count_pct']);
        $this->assertSame(90.0, $usage['weight_pct']);
        $this->assertSame(90.0, $usage['pct']);
        $this->assertSame('100.000', $usage['remaining']['kg']);
    }

    public function test_a_kind_the_limit_does_not_mention_is_reported_not_guessed(): void
    {
        $usage = Quota::usage(null, ['bale' => 10], '0', ['bale' => 5, 'drum' => 3, '' => 2]);

        $this->assertSame(50.0, $usage['pct']);
        $this->assertSame(5, $usage['uncounted']);
    }

    public function test_without_any_limit_there_is_no_percentage(): void
    {
        $usage = Quota::usage(null, [], '500', ['bale' => 5]);

        $this->assertNull($usage['pct']);
        $this->assertSame(0, $usage['uncounted']);
        $this->assertNull(Quota::level($usage['pct'], 90));
    }

    public function test_levels(): void
    {
        $this->assertNull(Quota::level(89.9, 90));
        $this->assertSame('warning', Quota::level(90.0, 90));
        $this->assertSame('warning', Quota::level(100.0, 90));
        $this->assertSame('exceeded', Quota::level(100.1, 90));
    }
}
