<?php

namespace App\Modules\Documents\Resources;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Documents\Models\HazardousWasteManifest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * @mixin HazardousWasteManifest
 */
final class HazardousWasteManifestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $signatures = DB::table('document_signatures as s')->leftJoin('users as u', 'u.id', '=', 's.signer_user_id')
            ->leftJoin('companies as c', 'c.id', '=', 's.signer_company_id')
            ->where('s.document_id', $this->document_id)->orderBy('s.sequence')
            ->get(['s.signer_party', 's.sequence', 's.status', 's.signed_at', 'u.first_name', 'u.last_name', 'c.ulid as company', 'c.legal_name']);

        return [
            'ulid' => $this->ulid,
            'manifest_number' => $this->manifest_number,
            'status' => $this->status,
            'pickup' => DB::table('pickups')->where('id', $this->pickup_id)->first(['ulid', 'pickup_number']),
            'regulatory_code' => app(ReferenceCodes::class)->code('regulatory_waste_codes', $this->regulatory_waste_code_id),
            'waste_type' => DB::table('waste_types')->where('id', $this->waste_type_id)->value('name'),
            'package_count' => $this->package_count,
            'declared_weight_kg' => $this->declared_weight_kg,
            'received_weight_kg' => $this->received_weight_kg,
            'producer' => $this->producer_snapshot,
            'transporter' => $this->transporter_snapshot,
            'receiver' => $this->receiver_snapshot,
            'signatures' => $signatures->map(fn ($row) => [
                'party' => $row->signer_party, 'sequence' => (int) $row->sequence, 'status' => $row->status,
                'company' => ['ulid' => $row->company, 'legal_name' => $row->legal_name],
                'signed_by' => $row->status === 'signed' ? trim("{$row->first_name} {$row->last_name}") : null,
                'signed_at' => $row->signed_at,
            ]),
            // The company expected to sign next, so each party's screen knows whether to offer the action.
            'awaiting_company' => $signatures->firstWhere('status', 'pending')?->company,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
