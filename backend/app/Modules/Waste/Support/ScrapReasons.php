<?php

namespace App\Modules\Waste\Support;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The company's scrap reasons, as a lot names them: by ULID, only an active reason of the company.
 */
final class ScrapReasons
{
    /** A reason a lot may be given now: one of the company's, still in use. */
    public static function rule(): Exists
    {
        return Rule::exists('scrap_reasons', 'ulid')->where(fn ($query) => $query->where('company_id', app(TenantContext::class)->companyId())->where('is_active', true));
    }

    public static function idOf(?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }
        $id = DB::table('scrap_reasons')->where('company_id', app(TenantContext::class)->companyId())->where('ulid', strtoupper($ulid))->value('id');

        return $id === null ? null : (int) $id;
    }
}
