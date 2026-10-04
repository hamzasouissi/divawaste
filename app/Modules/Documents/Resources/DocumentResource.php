<?php

namespace App\Modules\Documents\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'document_type' => $this->document_type?->value,
            'title' => $this->title,
            'reference_number' => $this->reference_number,
            'status' => $this->status?->value,
            'is_regulatory' => $this->is_regulatory,

            'site_id' => $this->site_id,
            'pickup_id' => $this->pickup?->ulid,

            'shared_with_company_id' => $this->shared_with_company_id,
            'uploaded_by_company_id' => $this->uploaded_by_company_id,

            'stored_file' => $this->whenLoaded(
                'storedFile',
                fn () => [
                    'ulid' => $this->storedFile->ulid,
                    'original_name' => $this->storedFile->original_name,
                    'mime_type' => $this->storedFile->mime_type,
                    'size_bytes' => $this->storedFile->size_bytes,
                    'checksum_sha256' => $this->storedFile->checksum_sha256,
                    'retention_until' => $this->storedFile->retention_until?->toDateString(),
                ],
            ),

            'issued_at' => $this->issued_at?->toISOString(),
            'retention_until' => $this->retention_until?->toDateString(),

            'source_snapshot' => $this->source_snapshot,

            'signatures' => $this->whenLoaded(
                'signatures',
                fn () => $this->signatures->map(
                    fn ($signature) => [
                        'id' => $signature->ulid,
                        'signer_party' => $signature->signer_party?->value,
                        'status' => $signature->status?->value,
                        'sequence' => $signature->sequence,
                        'signed_at' => $signature->signed_at?->toISOString(),
                    ]
                )->values(),
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
