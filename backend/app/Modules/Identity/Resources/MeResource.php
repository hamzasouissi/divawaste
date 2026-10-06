<?php

namespace App\Modules\Identity\Resources;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

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
            'phone' => $this->phone,
            'locale' => $this->locale,
            'email_verified' => $this->email_verified_at !== null,
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            // Platform staff only; tenant roles and permissions per site come from GET /me/access.
            'platform_roles' => $this->getRoleNames(),
            'platform_permissions' => $this->getAllPermissions()->pluck('name')->values(),
            // Every membership, whatever the company status, so a pending or suspended account can be explained.
            'memberships' => DB::table('company_users as m')->join('companies as c', 'c.id', '=', 'm.company_id')
                ->where('m.user_id', $this->id)->where('m.status', 'active')->orderBy('c.legal_name')
                ->get(['c.ulid', 'c.legal_name', 'c.company_type', 'c.status', 'c.rejection_reason', 'c.suspension_reason'])
                ->map(fn ($row) => [
                    'company' => $row->ulid,
                    'legal_name' => $row->legal_name,
                    'company_type' => $row->company_type,
                    'status' => $row->status,
                    'reason' => match ($row->status) {
                        'rejected' => $row->rejection_reason,
                        'suspended' => $row->suspension_reason,
                        default => null,
                    },
                ]),
        ];
    }
}
