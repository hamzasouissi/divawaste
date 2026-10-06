<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Colours work like waste types: the platform provides default ones, and each company can add its own.
 * A company sees the defaults and its own colours, never another company's.
 */
final class Colors
{
    /**
     * Colours the company may read: the defaults and its own.
     */
    public static function visibleTo(int $companyId): Builder
    {
        return DB::table('color_families')->where(fn (Builder $query) => $query->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    /**
     * Validation rule for a colour code chosen on a lot or a waste type.
     */
    public static function rule(int $companyId): Exists
    {
        return Rule::exists('color_families', 'code')->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('company_id')->orWhere('company_id', $companyId));
    }
}
