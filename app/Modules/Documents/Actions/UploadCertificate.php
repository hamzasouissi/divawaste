<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Documents\DTOs\UploadCertificateData;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\DocumentType;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentWasteLot;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Pickups\Models\PickupLot;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class UploadCertificate
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FileStorage $fileStorage,
        private readonly DatabaseManager $db,
        private readonly IssueDocument $issueDocument,
    ) {}

    public function handle(
        Pickup $pickup,
        UploadCertificateData $data,
        mixed $actor,
    ): Document {
        $actorCompanyId = $this->context->companyId();
        $actorId = is_object($actor) ? (int) ($actor->id ?? 0) : (int) $actor;

        if ($actorCompanyId === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $ownerCompanyId = (int) $pickup->company_id;

        if (
            $actorCompanyId !== $ownerCompanyId
            && (int) $pickup->provider_company_id !== $actorCompanyId
        ) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $status = $pickup->status;
        $statusValue = $status instanceof PickupStatus
            ? $status->value
            : strtolower((string) ($status ?? ''));

        if (! in_array($statusValue, [
            PickupStatus::Collected->value,
            PickupStatus::Received->value,
        ], true)) {
            throw new BusinessRuleViolation(
                'CERTIFICATE_PICKUP_NOT_READY',
                'The certificate can only be uploaded when the pickup is collected or received.'
            );
        }

        $ownerCompany = Company::query()->findOrFail($ownerCompanyId);

        $storedFile = null;

        try {
            $document = $this->context->runAs($ownerCompany, function () use (
                $pickup,
                $data,
                $actor,
                $actorId,
                $actorCompanyId,
                $ownerCompany,
                $ownerCompanyId,
                &$storedFile,
            ): Document {
                $pickup = Pickup::query()->findOrFail($pickup->id);

                $requestedLotValues = array_values(array_unique(
                    array_map('strtoupper', $data->lotNumbers)
                ));

                $lotIdsFromUlids = WasteLot::query()
                    ->whereIn('ulid', $requestedLotValues)
                    ->pluck('id')
                    ->all();

                $lines = PickupLot::query()
                    ->where('pickup_id', $pickup->id)
                    ->where(function ($query) use ($requestedLotValues, $lotIdsFromUlids): void {
                        $query->whereIn('lot_number_snapshot', $requestedLotValues);

                        if ($lotIdsFromUlids !== []) {
                            $query->orWhereIn('waste_lot_id', $lotIdsFromUlids);
                        }
                    })
                    ->orderBy('id')
                    ->get();

                $resolvedKeys = $lines
                    ->map(fn (PickupLot $line): string => strtoupper((string) $line->lot_number_snapshot))
                    ->merge(
                        WasteLot::query()
                            ->whereIn('id', $lines->pluck('waste_lot_id')->all())
                            ->pluck('ulid')
                            ->map(fn (string $ulid): string => strtoupper($ulid))
                    )
                    ->unique()
                    ->values()
                    ->all();

                if ($lines->count() !== count($requestedLotValues) || count(array_intersect($requestedLotValues, $resolvedKeys)) !== count($requestedLotValues)) {
                    $found = $lines
                        ->pluck('lot_number_snapshot')
                        ->map(fn ($value) => strtoupper((string) $value))
                        ->all();

                    $missing = array_values(array_diff($requestedLotValues, $found));

                    throw new BusinessRuleViolation(
                        'CERTIFICATE_LOTS_NOT_IN_PICKUP',
                        'Some lot identifiers do not belong to this pickup: '.implode(', ', $missing)
                    );
                }

                $retentionUntil = now()->addYears(10);

                $storedFile = $this->fileStorage->store(
                    file: $data->file,
                    company: $ownerCompany,
                    purpose: 'certificates',
                    uploadedByUserId: $actorId,
                    uploadedByCompanyId: $actorCompanyId,
                    retentionUntil: $retentionUntil,
                );

                $snapshot = [
                    'pickup' => [
                        'ulid' => $pickup->ulid,
                        'status' => $pickup->status?->value,
                        'requested_date' => $pickup->requested_date?->toDateString(),
                        'site_id' => $pickup->site_id,
                        'provider_company_id' => $pickup->provider_company_id,
                        'transporter_company_id' => $pickup->transporter_company_id,
                    ],
                    'lots' => $lines->map(
                        fn (PickupLot $line): array => [
                            'lot_number' => $line->lot_number_snapshot,
                            'waste_type' => $line->waste_type_label_snapshot,
                            'regulatory_waste_code' => $line->regulatory_code_snapshot,
                            'is_hazardous' => (bool) $line->is_hazardous,
                            'declared_weight_kg' => $line->declared_weight_kg !== null
                                ? (string) $line->declared_weight_kg
                                : null,
                            'loaded_weight_kg' => $line->departure_weight_kg !== null
                                ? (string) $line->departure_weight_kg
                                : null,
                            'received_weight_kg' => $line->received_weight_kg !== null
                                ? (string) $line->received_weight_kg
                                : null,
                        ]
                    )->values()->all(),
                ];

                $documentType = match (strtolower((string) $data->type)) {
                    'destruction' => DocumentType::DestructionCertificate,
                    default => DocumentType::RecyclingCertificate,
                };

                $document = Document::query()->create([
                    'document_type' => $documentType,
                    'title' => $data->title
                        ?? match ($documentType) {
                            DocumentType::DestructionCertificate => 'Destruction certificate - Pickup '.$pickup->ulid,
                            default => 'Recycling certificate - Pickup '.$pickup->ulid,
                        },
                    'reference_number' => null,
                    'status' => DocumentStatus::Draft,
                    'is_regulatory' => true,
                    'site_id' => $pickup->site_id,
                    'pickup_id' => $pickup->id,
                    'period_start' => null,
                    'period_end' => null,
                    'shared_with_company_id' => $actorCompanyId === $ownerCompanyId
                        ? null
                        : $actorCompanyId,
                    'uploaded_by_company_id' => $actorCompanyId,
                    'stored_file_id' => $storedFile->id,
                    'checksum_sha256' => $storedFile->checksum_sha256,
                    'source_snapshot' => $snapshot,
                    'retention_until' => $retentionUntil,
                    'legal_hold' => false,
                ]);

                foreach ($lines as $line) {
                    DocumentWasteLot::query()->create([
                        'document_id' => $document->id,
                        'waste_lot_id' => $line->waste_lot_id,
                    ]);
                }

                return $this->issueDocument->handle($document);
            });

            return $document->fresh([
                'storedFile',
                'wasteLots',
                'pickup',
                'signatures',
            ]);
        } catch (\Throwable $e) {
            if ($storedFile !== null) {
                Storage::disk($storedFile->disk)->delete($storedFile->path);
            }

            throw $e;
        }
    }
}
