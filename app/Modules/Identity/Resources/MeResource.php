<?php

namespace App\Modules\Identity\Resources;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'locale' => $this->locale,
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            // Platform staff roles only; tenant roles per site arrive with memberships.
            'platform_roles' => $this->getRoleNames(),
        ];
    }
}
