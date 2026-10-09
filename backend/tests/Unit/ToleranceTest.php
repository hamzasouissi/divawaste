<?php

namespace Tests\Unit;

use App\Modules\Catalog\Support\UnitConversion;
use App\Modules\Waste\Support\Tolerance;
use PHPUnit\Framework\TestCase;

class ToleranceTest extends TestCase
{
    public function test_the_gap_is_a_percentage_of_the_reference_signed_and_absolute(): void
    {
        $over = Tolerance::evaluate('100', '108', ['warning_pct' => '5.00', 'blocking_pct' => '10.00']);
        $this->assertSame(['status' => 'warning', 'difference' => '8.000', 'pct' => '8.00', 'pct_abs' => '8.00'], $over);

        $under = Tolerance::evaluate('250', '220', ['warning_pct' => '5.00', 'blocking_pct' => '10.00']);
        $this->assertSame(['status' => 'blocking', 'difference' => '-30.000', 'pct' => '-12.00', 'pct_abs' => '12.00'], $under);

        $this->assertSame('within', Tolerance::evaluate('100', '103', ['warning_pct' => '5.00'])['status']);
    }

    public function test_a_limit_is_exceeded_only_strictly_above_it(): void
    {
        $rule = ['warning_pct' => '5.00', 'blocking_pct' => '10.00'];
        $this->assertSame('within', Tolerance::evaluate('100', '95', $rule)['status']);
        $this->assertSame('warning', Tolerance::evaluate('100', '94.99', $rule)['status']);
        $this->assertSame('warning', Tolerance::evaluate('100', '110', $rule)['status']);
        $this->assertSame('blocking', Tolerance::evaluate('100', '110.01', $rule)['status']);
        // A rule may only block, or only warn.
        $this->assertSame('within', Tolerance::evaluate('100', '109', ['blocking_pct' => '10.00'])['status']);
        $this->assertSame('warning', Tolerance::evaluate('100', '300', ['warning_pct' => '5.00'])['status']);
    }

    public function test_a_directional_rule_counts_one_side_and_still_reports_the_signed_gap(): void
    {
        $excess = ['warning_pct' => '5.00', 'direction' => 'excess'];
        $this->assertSame('within', Tolerance::evaluate('100', '80', $excess)['status']);
        $this->assertSame('-20.00', Tolerance::evaluate('100', '80', $excess)['pct']);
        $this->assertSame('warning', Tolerance::evaluate('100', '106', $excess)['status']);

        $shortage = ['warning_pct' => '5.00', 'direction' => 'shortage'];
        $this->assertSame('within', Tolerance::evaluate('100', '150', $shortage)['status']);
        $this->assertSame('warning', Tolerance::evaluate('100', '90', $shortage)['status']);
    }

    public function test_without_a_usable_reference_there_is_no_percentage(): void
    {
        $rule = ['warning_pct' => '5.00'];
        foreach ([null, '0', '0.000', '-4', 'abc'] as $reference) {
            $result = Tolerance::evaluate($reference, '12', $rule);
            $this->assertSame('no_reference', $result['status']);
            $this->assertNull($result['pct']);
            $this->assertNull($result['pct_abs']);
        }
        // The difference is known against a zero, unknown against nothing.
        $this->assertSame('12.000', Tolerance::evaluate('0', '12', $rule)['difference']);
        $this->assertNull(Tolerance::evaluate(null, '12', $rule)['difference']);
        // Nothing expected, nothing measured: no gap.
        $this->assertSame('within', Tolerance::evaluate('0', '0', $rule)['status']);
        // Nothing measured yet is not a zero.
        $this->assertSame('not_measured', Tolerance::evaluate('100', null, $rule)['status']);
        $this->assertSame('not_measured', Tolerance::evaluate('100', '-1', $rule)['status']);
    }

    public function test_the_worst_outcome_wins_and_says_whether_someone_must_look(): void
    {
        $this->assertSame('blocking', Tolerance::worst(['within', 'blocking', 'warning']));
        $this->assertSame('warning', Tolerance::worst(['within', null, 'warning', 'no_reference']));
        $this->assertSame('no_reference', Tolerance::worst(['within', 'no_reference']));
        $this->assertNull(Tolerance::worst([]));
        $this->assertTrue(Tolerance::needsReview('no_reference'));
        $this->assertFalse(Tolerance::needsReview('within'));
        $this->assertFalse(Tolerance::needsReview(null));
    }

    public function test_quantities_compare_only_in_the_same_dimension_unless_the_type_states_a_conversion(): void
    {
        $this->assertTrue(UnitConversion::comparable('kg', 't'));
        $this->assertTrue(UnitConversion::comparable('l', 'm3'));
        $this->assertFalse(UnitConversion::comparable('piece', 'kg'));
        $this->assertFalse(UnitConversion::comparable('m', 'kg'));

        $this->assertSame('2500.000', UnitConversion::convert('2.5', 't', 'kg'));
        $this->assertSame('0.250', UnitConversion::convert('250', 'l', 'm3'));
        // Pieces and kilograms: refused without the weight of one piece, computed with it.
        $this->assertNull(UnitConversion::convert('100', 'piece', 'kg'));
        $this->assertSame('35.000', UnitConversion::convert('100', 'piece', 'kg', null, '0.350'));
        $this->assertSame('100.000', UnitConversion::convert('35', 'kg', 'piece', null, '0.350'));
        // Litres and kilograms need the density; pieces and litres both conversions.
        $this->assertNull(UnitConversion::toKg('200', 'l'));
        $this->assertSame('180.000', UnitConversion::toKg('200', 'l', '900'));
        $this->assertNull(UnitConversion::convert('10', 'piece', 'l', '900'));
        $this->assertNull(UnitConversion::convert('10', 'm', 'kg', '900', '1'));
    }
}
