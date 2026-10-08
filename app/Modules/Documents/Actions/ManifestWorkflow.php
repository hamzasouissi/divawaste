<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Common\Support\NumberSequence;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\DocumentType;
use App\Modules\Documents\Enums\HazardousWasteManifestStatus as Status;
use App\Modules\Documents\Enums\SignatureStatus;
use App\Modules\Documents\Enums\SignerParty;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentSignature;
use App\Modules\Documents\Models\HazardousWasteManifest;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Pickups\Enums\PickupStatus;
use App\Modules\Pickups\Models\Pickup;
use App\Modules\Providers\Models\ExternalPartner;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * M5-02: waste tracking slip. Every waste that leaves a site has one, hazardous or not: one slip per regulatory waste
 * code of a pickup (per waste type when a non-hazardous waste has no code), pre-filled from the pickup (nothing is
 * retyped), signed in order by the producer, the transporter and the receiver.
 *
 * Each party signs electronically (who, when, from where, and the hash of what was signed) or by hand on the printed
 * slip. A slip signed electronically by everyone is frozen and archived at the last signature; as soon as one party
 * signed on paper, it is complete when the scan of the signed copy is attached — that scan is then the archived proof.
 */
final class ManifestWorkflow
{
    private const ORDER = [SignerParty::Producer, SignerParty::Transporter, SignerParty::Receiver];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FileStorage $files,
        private readonly IssueDocument $issue,
        private readonly NotifyUsers $notify,
    ) {}

    /**
     * @return Collection<int, HazardousWasteManifest> every slip of the pickup (existing ones are returned, not duplicated)
     */
    public function generate(Pickup $pickup, User $actor): Collection
    {
        if (! in_array($pickup->status, [PickupStatus::Confirmed, PickupStatus::Collected, PickupStatus::Received], true)) {
            throw new BusinessRuleViolation('MANIFEST_PICKUP_NOT_READY', 'The slip is issued once the pickup has been confirmed.');
        }

        return DB::transaction(function () use ($pickup) {
            $lines = DB::table('pickup_lots as pl')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')
                ->where('pl.pickup_id', $pickup->id)->where('pl.line_status', '!=', 'not_loaded')
                ->get(['pl.id', 'pl.regulatory_waste_code_id', 'pl.is_hazardous', 'pl.declared_weight_kg', 'pl.departure_weight_kg', 'l.waste_type_id', 'l.packaging_type_id']);

            if ($lines->isEmpty()) {
                throw new BusinessRuleViolation('MANIFEST_NO_LOTS', 'This pickup carries no lot: there is nothing to put on a slip.');
            }
            if ($lines->contains(fn ($line) => $line->is_hazardous && $line->regulatory_waste_code_id === null)) {
                throw new BusinessRuleViolation('MANIFEST_CODE_MISSING', 'A hazardous lot has no regulatory waste code: complete the waste type first.');
            }

            $owner = Company::query()->findOrFail($pickup->company_id);
            $site = DB::table('sites')->where('id', $pickup->site_id)->first(['name', 'address_line1', 'city', 'regulatory_identifier']);
            $party = fn (Company $company) => ['legal_name' => $company->legal_name, 'tax_id' => $company->tax_id,
                'address' => trim("{$company->address_line1} {$company->postal_code} {$company->city}"), 'phone' => $company->phone];
            // Each party is a company of the platform (it signs itself) or a partner without an account (the owner
            // records its handwritten signature). Without a transporter, the receiver carries the waste.
            $receiver = $pickup->provider_company_id !== null
                ? ['company' => $pickup->provider_company_id, 'snapshot' => $party(Company::query()->findOrFail($pickup->provider_company_id))]
                : ['company' => null, 'snapshot' => ExternalPartner::query()->findOrFail($pickup->external_provider_id)->snapshot()];
            $transporter = match (true) {
                $pickup->transporter_company_id !== null => ['company' => $pickup->transporter_company_id, 'snapshot' => $party(Company::query()->findOrFail($pickup->transporter_company_id))],
                $pickup->external_transporter_id !== null => ['company' => null, 'snapshot' => ExternalPartner::query()->findOrFail($pickup->external_transporter_id)->snapshot()],
                default => $receiver,
            };

            // One slip per regulatory code; a waste without a code (never a hazardous one) gets one per waste type.
            foreach ($lines->groupBy(fn ($line) => $line->regulatory_waste_code_id === null ? "type-{$line->waste_type_id}" : "code-{$line->regulatory_waste_code_id}") as $group) {
                $first = $group->first();
                $exists = HazardousWasteManifest::query()->where('pickup_id', $pickup->id)->where('status', '!=', Status::Cancelled)
                    ->when($first->regulatory_waste_code_id !== null, fn ($query) => $query->where('regulatory_waste_code_id', $first->regulatory_waste_code_id),
                        fn ($query) => $query->whereNull('regulatory_waste_code_id')->where('waste_type_id', $first->waste_type_id))->exists();
                if ($exists) {
                    continue;
                }

                $year = now()->format('Y');
                $number = NumberSequence::next($owner->id, 'manifest', "BSD-{$year}-", $year, 4);
                $document = Document::query()->create([
                    'document_type' => DocumentType::HazardousManifest, 'title' => "Bordereau de suivi {$number}", 'reference_number' => $number,
                    'status' => DocumentStatus::PendingSignatures, 'is_regulatory' => true, 'site_id' => $pickup->site_id, 'pickup_id' => $pickup->id,
                    'shared_with_company_id' => $receiver['company'], 'uploaded_by_company_id' => $owner->id,
                ]);
                DB::table('document_waste_lots')->insert(DB::table('pickup_lots')->whereIn('id', $group->pluck('id'))->pluck('waste_lot_id')
                    ->map(fn ($lotId) => ['company_id' => $owner->id, 'document_id' => $document->id, 'waste_lot_id' => $lotId, 'created_at' => now(), 'updated_at' => now()])->all());

                $manifest = HazardousWasteManifest::query()->create([
                    'manifest_number' => $number, 'pickup_id' => $pickup->id, 'site_id' => $pickup->site_id, 'document_id' => $document->id,
                    'status' => Status::PendingProducerSignature, 'regulatory_waste_code_id' => $first->regulatory_waste_code_id,
                    'is_hazardous' => (bool) $first->is_hazardous,
                    'waste_type_id' => $first->waste_type_id, 'packaging_type_id' => $first->packaging_type_id,
                    'package_count' => $group->count(),
                    'declared_weight_kg' => $group->reduce(fn ($sum, $line) => bcadd($sum, (string) ($line->departure_weight_kg ?? $line->declared_weight_kg), 3), '0'),
                    'producer_snapshot' => $party($owner) + ['site' => $site->name, 'site_address' => trim("{$site->address_line1} {$site->city}"), 'site_identifier' => $site->regulatory_identifier],
                    'transporter_snapshot' => $transporter['snapshot'], 'receiver_snapshot' => $receiver['snapshot'],
                    'transporter_company_id' => $transporter['company'], 'receiver_company_id' => $receiver['company'],
                    'collected_at' => $pickup->collected_at,
                ]);
                DB::table('pickup_lots')->whereIn('id', $group->pluck('id'))->update(['hazardous_waste_manifest_id' => $manifest->id]);

                foreach ([$owner->id, $transporter['company'], $receiver['company']] as $sequence => $companyId) {
                    DocumentSignature::query()->create([
                        'document_id' => $document->id, 'signer_party' => self::ORDER[$sequence], 'sequence' => $sequence + 1,
                        'signer_company_id' => $companyId, 'status' => SignatureStatus::Pending,
                    ]);
                }
                $this->requestSignature($manifest, SignerParty::Producer);
            }

            return HazardousWasteManifest::query()->where('pickup_id', $pickup->id)->orderBy('id')->get();
        });
    }

    /**
     * Signs for the party whose turn it is.
     *
     * Electronically: the caller belongs to that party and has already re-entered his password. On paper: the party
     * signed by hand on the printed slip; its name and the date are typed in — by the party itself, or by the owner of
     * the slip for a partner that has no account (which can only sign that way).
     *
     * @param  array{signer_name: string, signed_on: string}|null  $paper  null = electronic signature
     */
    public function sign(HazardousWasteManifest $manifest, User $actor, Request $request, ?array $paper = null): HazardousWasteManifest
    {
        $signerCompanyId = $this->context->companyId();

        return DB::transaction(fn () => $this->context->runAs(Company::query()->findOrFail($manifest->company_id), function () use ($manifest, $actor, $request, $signerCompanyId, $paper) {
            $manifest = HazardousWasteManifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            $next = DocumentSignature::query()->where('document_id', $manifest->document_id)->where('status', SignatureStatus::Pending)->orderBy('sequence')->first();

            if ($next === null || in_array($manifest->status, [Status::Completed, Status::Cancelled, Status::PendingSignedCopy], true)) {
                throw new BusinessRuleViolation('MANIFEST_NOT_SIGNABLE', 'This slip is not waiting for a signature.');
            }
            // A partner without an account has no user: the owner of the slip records its handwritten signature.
            $external = $next->signer_company_id === null;
            if ((int) ($external ? $manifest->company_id : $next->signer_company_id) !== $signerCompanyId) {
                throw new BusinessRuleViolation('NOT_YOUR_TURN', 'This slip is waiting for the signature of another party.');
            }
            if ($external && $paper === null) {
                throw new BusinessRuleViolation('PAPER_SIGNATURE_REQUIRED', 'This party has no account: record the signature it made by hand on the printed slip.');
            }

            $next->forceFill([
                'status' => SignatureStatus::Signed, 'signer_user_id' => $actor->id,
                'signer_name' => $paper['signer_name'] ?? null,
                'signed_at' => $paper === null ? now() : Carbon::parse($paper['signed_on'])->startOfDay(),
                'signed_payload_hash' => hash('sha256', (string) json_encode($this->payload($manifest), JSON_UNESCAPED_UNICODE)),
                'signature_method' => $paper === null ? 'password_confirmation' : 'paper',
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ])->save();

            // The same company may hold two roles in a row (a provider that transports and receives): each is signed separately.
            $following = DocumentSignature::query()->where('document_id', $manifest->document_id)->where('status', SignatureStatus::Pending)->orderBy('sequence')->first();

            if ($following !== null) {
                $manifest->forceFill(['status' => match ($following->signer_party) {
                    SignerParty::Transporter => Status::PendingTransporterSignature,
                    SignerParty::Receiver => Status::PendingReceiverSignature,
                    default => Status::PendingProducerSignature,
                }])->save();
                $this->requestSignature($manifest, $following->signer_party);

                return $manifest;
            }

            // Handwritten signatures exist on paper only: the slip is complete once that paper is attached.
            if (DocumentSignature::query()->where('document_id', $manifest->document_id)->where('signature_method', 'paper')->exists()) {
                $manifest->forceFill(['status' => Status::PendingSignedCopy])->save();
                $this->notify->toSitePermission($manifest->site_id, 'documents.sign', 'document.signed_copy_requested',
                    ['number' => $manifest->manifest_number], '/compliance/manifests/'.$manifest->ulid);

                return $manifest;
            }

            return $this->complete($manifest, $actor, null);
        }));
    }

    /**
     * The scan of the slip carrying the handwritten signatures: it closes the slip and becomes the archived document.
     */
    public function attachSignedCopy(HazardousWasteManifest $manifest, UploadedFile $scan, User $actor): HazardousWasteManifest
    {
        return DB::transaction(function () use ($manifest, $scan, $actor) {
            $manifest = HazardousWasteManifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            if ($manifest->status !== Status::PendingSignedCopy) {
                throw new BusinessRuleViolation('MANIFEST_NOT_AWAITING_COPY', 'This slip is not waiting for its signed copy.');
            }

            return $this->complete($manifest, $actor, $scan);
        });
    }

    private function complete(HazardousWasteManifest $manifest, User $actor, ?UploadedFile $scan): HazardousWasteManifest
    {
        $pickup = Pickup::query()->findOrFail($manifest->pickup_id);
        $manifest->forceFill(['status' => Status::Completed, 'received_at' => $pickup->received_at, 'collected_at' => $pickup->collected_at,
            'received_weight_kg' => DB::table('pickup_lots')->where('hazardous_waste_manifest_id', $manifest->id)->sum('received_weight_kg') ?: null])->save();

        $owner = Company::query()->findOrFail($manifest->company_id);
        $file = $scan === null
            ? $this->files->storeContent($this->pdf($manifest), "{$manifest->manifest_number}.pdf", 'application/pdf', $owner, 'manifests', $actor->id, now()->addYears(10))
            : $this->files->store($scan, $owner, 'manifests', $actor->id, $owner->id, now()->addYears(10));
        $document = Document::query()->findOrFail($manifest->document_id);
        $document->forceFill(['stored_file_id' => $file->id, 'checksum_sha256' => $file->checksum_sha256, 'source_snapshot' => $this->payload($manifest)])->save();
        $this->issue->handle($document);

        return $manifest;
    }

    /**
     * Current state of the slip as PDF: electronic signatures are printed, the others have a box to sign by hand.
     * (For a slip signed electronically by everyone, the archived file is this same rendering, frozen.)
     */
    public function pdf(HazardousWasteManifest $manifest): string
    {
        $payload = $this->payload($manifest);
        // The official label of the waste code is shown in the language of the document; the signed content is not changed.
        if ($payload['waste_code'] !== null) {
            $label = json_decode((string) DB::table('regulatory_waste_codes')->where('code', $payload['waste_code'])->value('label'), true);
            $payload['waste_label'] = $label[app()->getLocale()] ?? $payload['waste_label'];
        }
        $signatures = DB::table('document_signatures as s')->leftJoin('users as u', 'u.id', '=', 's.signer_user_id')
            ->where('s.document_id', $manifest->document_id)->orderBy('s.sequence')
            ->get(['s.signer_party', 's.status', 's.signed_at', 's.signed_payload_hash', 's.signature_method', 's.signer_name', 'u.first_name', 'u.last_name']);
        $timezone = (string) DB::table('companies')->where('id', $manifest->company_id)->value('timezone');

        return Pdf::loadView('pdf.manifest', compact('payload', 'signatures', 'timezone') + ['complete' => $manifest->status === Status::Completed])
            ->setPaper('a4')->output();
    }

    /**
     * What the parties sign: the business content of the slip, in a stable order.
     *
     * @return array<string, mixed>
     */
    public function payload(HazardousWasteManifest $manifest): array
    {
        $code = $manifest->regulatory_waste_code_id === null ? null
            : DB::table('regulatory_waste_codes')->where('id', $manifest->regulatory_waste_code_id)->first(['code', 'label']);
        $pickup = DB::table('pickups')->where('id', $manifest->pickup_id)->first(['pickup_number', 'requested_date', 'confirmed_date']);

        return [
            'manifest_number' => $manifest->manifest_number,
            'pickup_number' => $pickup->pickup_number,
            'pickup_date' => $pickup->confirmed_date ?? $pickup->requested_date,
            'is_hazardous' => (bool) $manifest->is_hazardous,
            'waste_code' => $code?->code,
            'waste_label' => $code === null ? null : (json_decode((string) $code->label, true)['fr'] ?? null),
            'waste_type' => DB::table('waste_types')->where('id', $manifest->waste_type_id)->value('name'),
            'package_count' => $manifest->package_count,
            'declared_weight_kg' => (string) $manifest->declared_weight_kg,
            'producer' => $manifest->producer_snapshot,
            'transporter' => $manifest->transporter_snapshot,
            'receiver' => $manifest->receiver_snapshot,
            'lots' => DB::table('pickup_lots')->where('hazardous_waste_manifest_id', $manifest->id)->orderBy('id')
                ->get(['lot_number_snapshot', 'declared_weight_kg', 'departure_weight_kg', 'received_weight_kg'])
                ->map(fn ($line) => ['lot_number' => $line->lot_number_snapshot, 'weight_kg' => (string) ($line->departure_weight_kg ?? $line->declared_weight_kg),
                    'received_weight_kg' => $line->received_weight_kg === null ? null : (string) $line->received_weight_kg])->all(),
        ];
    }

    private function requestSignature(HazardousWasteManifest $manifest, SignerParty $party): void
    {
        $params = ['number' => $manifest->manifest_number];
        $companyId = match ($party) {
            SignerParty::Producer => null,
            SignerParty::Transporter => $manifest->transporter_company_id,
            SignerParty::Receiver => $manifest->receiver_company_id,
        };

        // The producer signs itself; for a partner without an account, its people record the handwritten signature.
        if ($companyId === null) {
            $this->notify->toSitePermission($manifest->site_id, 'documents.sign', 'document.signature_requested', $params, '/compliance/manifests/'.$manifest->ulid);

            return;
        }

        $this->notify->toCompanyPermission((int) $companyId, 'provider.manifests.sign', 'document.signature_requested', $params, '/provider/manifests/'.$manifest->ulid);
    }
}
