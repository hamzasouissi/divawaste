<?php

namespace Tests\Unit;

use App\Modules\Common\Support\Constraint;
use PHPUnit\Framework\TestCase;

class ConstraintTest extends TestCase
{
    public function test_a_quantity_is_answered_in_its_own_unit(): void
    {
        $weight = Constraint::quantity('weight', 'kg', '450', '500', 95);
        $this->assertSame(['respected', 90.0, '50.000', null], [$weight['outcome'], $weight['pct'], $weight['remaining'], $weight['excess']]);

        $this->assertSame('warning', Constraint::quantity('weight', 'kg', '480', '500', 95)['outcome']);
        // Exactly at the limit is not above it.
        $this->assertSame('warning', Constraint::quantity('weight', 'kg', '500', '500', 95)['outcome']);

        $over = Constraint::quantity('lots', 'lot', '12', '10', 80, [], true, 0);
        $this->assertSame(['exceeded', 2, 0, 12, 10, true], [$over['outcome'], $over['excess'], $over['remaining'], $over['used'], $over['max'], $over['blocking']]);
    }

    public function test_a_missing_quantity_is_never_a_met_limit(): void
    {
        // Known weight below the limit, one lot nobody weighed: nobody can say the limit is met.
        $unknown = Constraint::quantity('weight', 'kg', '120', '500', 80, ['L-1', 'L-1', 'L-2']);
        $this->assertSame(['unverifiable', 2, ['L-1', 'L-2']], [$unknown['outcome'], $unknown['missing_count'], $unknown['missing_lots']]);

        // Known weight already above it: exceeded, and the lots that could not be counted are still named.
        $over = Constraint::quantity('weight', 'kg', '520', '500', 80, ['L-1']);
        $this->assertSame(['exceeded', '20.000', 1], [$over['outcome'], $over['excess'], $over['missing_count']]);
    }

    public function test_packagings_share_one_room(): void
    {
        // 50 bags of 100 and 25 boxes of 50: the room is full, not exceeded.
        $full = Constraint::packages('space', ['bag' => 100, 'box' => 50], ['bag' => 50, 'box' => 25], 90);
        $this->assertSame([100.0, 'warning'], [$full['pct'], $full['outcome']]);
        $this->assertSame([['packaging_type' => 'bag', 'used' => 50, 'max' => 100, 'remaining' => 0, 'excess' => null], ['packaging_type' => 'box', 'used' => 25, 'max' => 50, 'remaining' => 0, 'excess' => null]], $full['lines']);

        // One more bag is too many.
        $this->assertSame('exceeded', Constraint::packages('space', ['bag' => 100, 'box' => 50], ['bag' => 51, 'box' => 25], 90)['outcome']);

        // With one packaging the excess is a plain number of packages.
        $bags = Constraint::packages('packages', ['bag' => 300], ['bag' => 310, 'drum' => 4, '' => 2], 80, [], true);
        $this->assertSame(['exceeded', 10, 310, 300], [$bags['outcome'], $bags['lines'][0]['excess'], $bags['lines'][0]['used'], $bags['lines'][0]['max']]);

        // What the room cannot count makes it unverifiable, not respected.
        $this->assertSame('unverifiable', Constraint::packages('space', ['bag' => 100], ['bag' => 10, '' => 1], 90, ['L-9'])['outcome']);
    }

    public function test_the_overall_answer_comes_from_what_each_limit_requires_never_from_comparing_percentages(): void
    {
        // 90 % of the weight and 103 % of the bags: the bags fail, whatever the weight says.
        $weight = Constraint::quantity('weight', 'kg', '450', '500', 95, [], true);
        $bags = Constraint::packages('packages', ['bag' => 300], ['bag' => 310], 95, [], true);
        $this->assertSame(['outcome' => 'exceeded', 'blocked' => true, 'failing' => ['packages']], Constraint::overall([$weight, $bags]));

        // A limit that cannot be verified fails a blocking check too, and stays a different answer from "exceeded".
        $unknown = Constraint::quantity('weight', 'kg', '100', '500', 95, ['L-1'], true);
        $this->assertSame(['outcome' => 'exceeded', 'blocked' => true, 'failing' => ['weight', 'packages']], Constraint::overall([$unknown, $bags]));
        $this->assertSame(['outcome' => 'unverifiable', 'blocked' => true, 'failing' => ['weight']], Constraint::overall([$unknown]));

        // The same answers on limits that only alert block nothing.
        $load = Constraint::quantity('load', 'kg', '2100', '2000', 90);
        $this->assertSame(['outcome' => 'exceeded', 'blocked' => false, 'failing' => []], Constraint::overall([$load]));
        $this->assertSame(['outcome' => null, 'blocked' => false, 'failing' => []], Constraint::overall([]));
    }
}
