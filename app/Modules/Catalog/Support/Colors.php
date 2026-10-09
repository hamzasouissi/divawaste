<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Colours work like waste types: the platform provides default ones, and each company can add its own.
 * A company sees the defaults and its own colours, never another company's.
 *
 * The table also holds the reference catalogue (rows imported from a colour library, `source = import`). The
 * catalogue is a library to pick from: a company takes a colour out of it into its own list, and only that list is
 * offered on lots, waste types, materials and zones.
 */
final class Colors
{
    /** Where a row comes from: seeded by the platform, imported from a library, or typed by a company. */
    public const SOURCE_PLATFORM = 'platform';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_USER = 'user';

    /**
     * Colours the company may choose: the defaults and its own. The catalogue is not part of them.
     */
    public static function visibleTo(int $companyId): Builder
    {
        return DB::table('color_families')->where(fn (Builder $query) => $query
            ->where(fn (Builder $defaults) => $defaults->whereNull('company_id')->where('source', '!=', self::SOURCE_IMPORT))
            ->orWhere('company_id', $companyId));
    }

    /**
     * The reference catalogue: imported rows that belong to nobody.
     */
    public static function catalogue(): Builder
    {
        return DB::table('color_families')->whereNull('company_id')->where('source', self::SOURCE_IMPORT);
    }

    /**
     * Validation rule for a colour code chosen on a lot or a waste type.
     */
    public static function rule(int $companyId): Exists
    {
        return Rule::exists('color_families', 'code')->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $defaults) => $defaults->whereNull('company_id')->where('source', '!=', self::SOURCE_IMPORT))
                ->orWhere('company_id', $companyId));
    }

    /**
     * The columns derived from a HEX value, ready to be written next to it.
     *
     * @return array{hex_color: string|null, rgb_r: int|null, rgb_g: int|null, rgb_b: int|null, lab_l: float|null, lab_a: float|null, lab_b: float|null}
     */
    public static function derived(?string $hex): array
    {
        if ($hex === null) {
            return ['hex_color' => null, 'rgb_r' => null, 'rgb_g' => null, 'rgb_b' => null, 'lab_l' => null, 'lab_a' => null, 'lab_b' => null];
        }
        $rgb = ColorMath::rgb($hex);
        $lab = ColorMath::lab($hex);

        return ['hex_color' => $hex, 'rgb_r' => $rgb['r'], 'rgb_g' => $rgb['g'], 'rgb_b' => $rgb['b'], 'lab_l' => $lab['l'], 'lab_a' => $lab['a'], 'lab_b' => $lab['b']];
    }

    /**
     * Rows written before these columns existed, or by the reference seeder: fill what follows from what they hold.
     */
    public static function completeDerived(): void
    {
        DB::table('color_families')->whereNull('company_id')->where('source', self::SOURCE_USER)->update(['source' => self::SOURCE_PLATFORM]);
        foreach (DB::table('color_families')->whereNotNull('hex_color')
            ->where(fn (Builder $query) => $query->whereNull('lab_l')->orWhereRaw("UPPER(hex_color) != CONCAT('#', LPAD(HEX(rgb_r), 2, '0'), LPAD(HEX(rgb_g), 2, '0'), LPAD(HEX(rgb_b), 2, '0'))"))->get(['id', 'hex_color']) as $row) {
            $hex = ColorMath::normalizeHex($row->hex_color);
            if ($hex !== null) {
                DB::table('color_families')->where('id', $row->id)->update(self::derived($hex));
            }
        }
    }

    /**
     * Whether anything still names this colour: such a colour is withdrawn, never deleted.
     */
    public static function inUse(int $id): bool
    {
        return DB::table('waste_lots')->where('color_family_id', $id)->exists()
            || DB::table('waste_types')->where('default_color_family_id', $id)->exists()
            || DB::table('raw_materials')->where('color_family_id', $id)->exists()
            || DB::table('zone_accepted_colors')->where('color_family_id', $id)->exists();
    }
}
