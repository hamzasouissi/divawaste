<?php

namespace App\Modules\Sites\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Sites\DTOs\CreateSiteData;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\DB;

final class CreateSite
{
    public function handle(CreateSiteData $data, User $actor): Site
    {
        return DB::transaction(fn () => Site::query()->create([
            'code' => $data->code,
            'name' => $data->name,
            'site_kind' => $data->siteKind,
            'country_id' => DB::table('countries')->where('iso2', $data->countryIso2)->value('id'),
            'address_line1' => $data->addressLine1,
            'city' => $data->city,
            'postal_code' => $data->postalCode,
            'region' => $data->region,
            'timezone' => $data->timezone,
            'regulatory_identifier' => $data->regulatoryIdentifier,
            'capacity_kg' => $data->capacityKg,
            'activated_at' => now(),
            'created_by_user_id' => $actor->id,
        ]));
    }
}
