<?php

namespace Tests\Unit;

use App\Modules\Catalog\Support\ColorMath;
use PHPUnit\Framework\TestCase;

class ColorMathTest extends TestCase
{
    public function test_hex_values_are_normalised_and_anything_else_is_refused(): void
    {
        $this->assertSame('#0A6C74', ColorMath::normalizeHex('#0a6c74'));
        $this->assertSame('#0A6C74', ColorMath::normalizeHex(' 0A6C74 '));
        $this->assertSame('#AABBCC', ColorMath::normalizeHex('#abc'));
        foreach (['green', '#12', '#12345', '#GGGGGG', '#1234567', '', null, 123456, ['#FFFFFF']] as $bad) {
            $this->assertNull(ColorMath::normalizeHex($bad));
        }
    }

    public function test_hex_and_rgb_convert_both_ways(): void
    {
        $this->assertSame(['r' => 15, 'g' => 76, 'b' => 129], ColorMath::rgb('#0F4C81'));
        $this->assertSame('#0F4C81', ColorMath::hex(15, 76, 129));
        $this->assertSame(255, ColorMath::channel('255'));
        $this->assertSame(0, ColorMath::channel(0));
        foreach ([256, -1, '12.5', 'x', null, 1.5] as $bad) {
            $this->assertNull(ColorMath::channel($bad));
        }
    }

    public function test_lab_values_follow_the_srgb_d65_definition(): void
    {
        $white = ColorMath::lab('#FFFFFF');
        $this->assertEqualsWithDelta(100.0, $white['l'], 0.01);
        $this->assertEqualsWithDelta(0.0, $white['a'], 0.01);
        $this->assertEqualsWithDelta(0.0, $white['b'], 0.01);
        $this->assertEqualsWithDelta(0.0, ColorMath::lab('#000000')['l'], 0.001);

        $red = ColorMath::lab('#FF0000');
        $this->assertEqualsWithDelta(53.24, $red['l'], 0.02);
        $this->assertEqualsWithDelta(80.09, $red['a'], 0.02);
        $this->assertEqualsWithDelta(67.20, $red['b'], 0.02);
    }

    public function test_the_distance_matches_the_published_ciede2000_reference_pairs(): void
    {
        // Sharma, Wu & Dalal (2005), "The CIEDE2000 Color-Difference Formula", table 1.
        $pairs = [
            [[50.0000, 2.6772, -79.7751], [50.0000, 0.0000, -82.7485], 2.0425],
            [[50.0000, 3.1571, -77.2803], [50.0000, 0.0000, -82.7485], 2.8615],
            [[50.0000, -1.3802, -84.2814], [50.0000, 0.0000, -82.7485], 1.0000],
            [[50.0000, 2.5000, 0.0000], [50.0000, 0.0000, -2.5000], 4.3065],
            [[50.0000, 2.5000, 0.0000], [73.0000, 25.0000, -18.0000], 27.1492],
            [[60.2574, -34.0099, 36.2677], [60.4626, -34.1751, 39.4387], 1.2644],
            [[90.8027, -2.0831, 1.4410], [91.1528, -1.6435, 0.0447], 1.4441],
            [[2.0776, 0.0795, -1.1350], [0.9033, -0.0636, -0.5514], 0.9082],
        ];
        foreach ($pairs as [$one, $two, $expected]) {
            $a = ['l' => $one[0], 'a' => $one[1], 'b' => $one[2]];
            $b = ['l' => $two[0], 'a' => $two[1], 'b' => $two[2]];
            $this->assertEqualsWithDelta($expected, ColorMath::deltaE($a, $b), 0.0001);
            $this->assertEqualsWithDelta($expected, ColorMath::deltaE($b, $a), 0.0001);
        }
        $this->assertSame(0.0, ColorMath::deltaE(ColorMath::lab('#0F4C81'), ColorMath::lab('#0F4C81')));
    }
}
