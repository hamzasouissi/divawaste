<?php

namespace App\Modules\Documents\Resources;

use App\Modules\Documents\Models\Document;
use App\Modules\Pickups\Support\PartyDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parties = app(PartyDirectory::class);

        return [
            'ulid' => $this->ulid,
            'document_type' => $this->document_type->value,
            'title' => $this->title,
            'reference_number' => $this->reference_number,
            'status' => $this->status->value,
            'is_regulatory' => $this->is_regulatory,
            'site' => $this->site_id === null ? null : $parties->site($this->site_id),
            'pickup' => $this->pickup_id === null ? null : DB::table('pickups')->where('id', $this->pickup_id)->first(['ulid', 'pickup_number']),
            // Kept for existing consumers: the pickup's public identifier.
            'pickup_id' => $this->pickup_id === null ? null : DB::table('pickups')->where('id', $this->pickup_id)->value('ulid'),
            'uploaded_by' => $parties->company($this->uploaded_by_company_id),
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'has_file' => $this->stored_file_id !== null,
            'stored_file' => $this->whenLoaded('storedFile', fn () => $this->storedFile === null ? null : [
                'original_name' => $this->storedFile->original_name,
                'mime_type' => $this->storedFile->mime_type,
                'size_bytes' => $this->storedFile->size_bytes,
                'checksum_sha256' => $this->storedFile->checksum_sha256,
            ]),
            'lots' => $this->whenLoaded('wasteLots', fn () => $this->wasteLots->map(fn ($lot) => ['ulid' => $lot->ulid, 'lot_number' => $lot->lot_number])->values()),
            'issued_at' => $this->issued_at?->toIso8601ZuluString(),
            'retention_until' => $this->retention_until?->toDateString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
