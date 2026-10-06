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
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * M5-02: hazardous waste tracking slip. One slip per regulatory waste code of a pickup, pre-filled from the pickup
 * (nothing is retyped), signed in order by the producer, the transporter and the receiver. Each signature records
 * who, when, from where, and the hash of what was signed; the PDF is frozen and archived when the last party signs.
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
            throw new BusinessRuleViolation('MANIFEST_PICKUP_NOT_READY', 'The slip is issued once the provider has confirmed the pickup.');
        }

        return DB::transaction(function () use ($pickup) {
            $groups = DB::table('pickup_lots as pl')->join('waste_lots as l', 'l.id', '=', 'pl.waste_lot_id')
                ->where('pl.pickup_id', $pickup->id)->where('pl.is_hazardous', true)->where('pl.line_status', '!=', 'not_loaded')
                ->get(['pl.id', 'pl.regulatory_waste_code_id', 'pl.declared_weight_kg', 'pl.departure_weight_kg', 'l.waste_type_id', 'l.packaging_type_id'])
                ->groupBy('regulatory_waste_code_id');

            if ($groups->isEmpty()) {
                throw new BusinessRuleViolation('NO_HAZARDOUS_LOTS', 'This pickup carries no hazardous waste: no tracking slip is required.');
            }
            if ($groups->has('')) {
                throw new BusinessRuleViolation('MANIFEST_CODE_MISSING', 'A hazardous lot has no regulatory waste code: complete the waste type first.');
            }

            $owner = Company::query()->findOrFail($pickup->company_id);
            $site = DB::table('sites')->where('id', $pickup->site_id)->first(['name', 'address_line1', 'city', 'regulatory_identifier']);
            $receiver = Company::query()->findOrFail($pickup->provider_company_id);
            $transporter = $pickup->transporter_company_id === null ? $receiver : Company::query()->findOrFail($pickup->transporter_company_id);
            $party = fn (Company $company) => ['legal_name' => $company->legal_name, 'tax_id' => $company->tax_id,
                'address' => trim("{$company->address_line1} {$company->postal_code} {$company->city}"), 'phone' => $company->phone];

            foreach ($groups as $codeId => $lines) {
                if (HazardousWasteManifest::query()->where('pickup_id', $pickup->id)->where('regulatory_waste_code_id', $codeId)->where('status', '!=', Status::Cancelled)->exists()) {
                    continue;
                }

                $year = now()->format('Y');
                $number = NumberSequence::next($owner->id, 'manifest', "BSD-{$year}-", $year, 4);
                $document = Document::query()->create([
                    'document_type' => DocumentType::HazardousManifest, 'title' => "Bordereau de suivi {$number}", 'reference_number' => $number,
                    'status' => DocumentStatus::PendingSignatures, 'is_regulatory' => true, 'site_id' => $pickup->site_id, 'pickup_id' => $pickup->id,
                    'shared_with_company_id' => $receiver->id, 'uploaded_by_company_id' => $owner->id,
                ]);
                DB::table('document_waste_lots')->insert(DB::table('pickup_lots')->whereIn('id', $lines->pluck('id'))->pluck('waste_lot_id')
                    ->map(fn ($lotId) => ['company_id' => $owner->id, 'document_id' => $document->id, 'waste_lot_id' => $lotId, 'created_at' => now(), 'updated_at' => now()])->all());

                $manifest = HazardousWasteManifest::query()->create([
                    'manifest_number' => $number, 'pickup_id' => $pickup->id, 'site_id' => $pickup->site_id, 'document_id' => $document->id,
                    'status' => Status::PendingProducerSignature, 'regulatory_waste_code_id' => $codeId,
                    'waste_type_id' => $lines->first()->waste_type_id, 'packaging_type_id' => $lines->first()->packaging_type_id,
                    'package_count' => $lines->count(),
                    'declared_weight_kg' => $lines->reduce(fn ($sum, $line) => bcadd($sum, (string) ($line->departure_weight_kg ?? $line->declared_weight_kg), 3), '0'),
                    'producer_snapshot' => $party($owner) + ['site' => $site->name, 'site_address' => trim("{$site->address_line1} {$site->city}"), 'site_identifier' => $site->regulatory_identifier],
                    'transporter_snapshot' => $party($transporter), 'receiver_snapshot' => $party($receiver),
                    'transporter_company_id' => $transporter->id, 'receiver_company_id' => $receiver->id,
                    'collected_at' => $pickup->collected_at,
                ]);
                DB::table('pickup_lots')->whereIn('id', $lines->pluck('id'))->update(['hazardous_waste_manifest_id' => $manifest->id]);

                foreach ([$owner, $transporter, $receiver] as $sequence => $company) {
                    DocumentSignature::query()->create([
                        'document_id' => $document->id, 'signer_party' => self::ORDER[$sequence], 'sequence' => $sequence + 1,
                        'signer_company_id' => $company->id, 'status' => SignatureStatus::Pending,
                    ]);
                }
                $this->requestSignature($manifest, SignerParty::Producer);
            }

            return HazardousWasteManifest::query()->where('pickup_id', $pickup->id)->orderBy('id')->get();
        });
    }

    /**
     * Signs for the party whose turn it is. The caller has already re-entered his password.
     */
    public function sign(HazardousWasteManifest $manifest, User $actor, Request $request): HazardousWasteManifest
    {
        $signerCompanyId = $this->context->companyId();

        return DB::transaction(fn () => $this->context->runAs(Company::query()->findOrFail($manifest->company_id), function () use ($manifest, $actor, $request, $signerCompanyId) {
            $manifest = HazardousWasteManifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            $next = DocumentSignature::query()->where('document_id', $manifest->document_id)->where('status', SignatureStatus::Pending)->orderBy('sequence')->first();

            if ($next === null || in_array($manifest->status, [Status::Completed, Status::Cancelled], true)) {
                throw new BusinessRuleViolation('MANIFEST_NOT_SIGNABLE', 'This slip is not waiting for a signature.');
            }
            if ((int) $next->signer_company_id !== $signerCompanyId) {
                throw new BusinessRuleViolation('NOT_YOUR_TURN', 'This slip is waiting for the signature of another party.');
            }

            $next->forceFill([
                'status' => SignatureStatus::Signed, 'signer_user_id' => $actor->id, 'signed_at' => now(),
                'signed_payload_hash' => hash('sha256', (string) json_encode($this->payload($manifest), JSON_UNESCAPED_UNICODE)),
                'signature_method' => 'password_confirmation', 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
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

            $pickup = Pickup::query()->findOrFail($manifest->pickup_id);
            $manifest->forceFill(['status' => Status::Completed, 'received_at' => $pickup->received_at, 'collected_at' => $pickup->collected_at,
                'received_weight_kg' => DB::table('pickup_lots')->where('hazardous_waste_manifest_id', $manifest->id)->sum('received_weight_kg') ?: null])->save();

            $file = $this->files->storeContent($this->pdf($manifest), "{$manifest->manifest_number}.pdf", 'application/pdf',
                Company::query()->findOrFail($manifest->company_id), 'manifests', $actor->id, now()->addYears(10));
            $document = Document::query()->findOrFail($manifest->document_id);
            $document->forceFill(['stored_file_id' => $file->id, 'checksum_sha256' => $file->checksum_sha256, 'source_snapshot' => $this->payload($manifest)])->save();
            $this->issue->handle($document);

            return $manifest;
        }));
    }

    /**
     * Current state of the slip as PDF (the archived file once completed is this same rendering, frozen).
     */
    public function pdf(HazardousWasteManifest $manifest): string
    {
        $payload = $this->payload($manifest);
        // The official label of the waste code is shown in the language of the document; the signed content is not changed.
        $label = json_decode((string) DB::table('regulatory_waste_codes')->where('code', $payload['waste_code'])->value('label'), true);
        $payload['waste_label'] = $label[app()->getLocale()] ?? $payload['waste_label'];
        $signatures = DB::table('document_signatures as s')->leftJoin('users as u', 'u.id', '=', 's.signer_user_id')
            ->where('s.document_id', $manifest->document_id)->orderBy('s.sequence')
            ->get(['s.signer_party', 's.status', 's.signed_at', 's.signed_payload_hash', 'u.first_name', 'u.last_name']);
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
        $code = DB::table('regulatory_waste_codes')->where('id', $manifest->regulatory_waste_code_id)->first(['code', 'label']);
        $pickup = DB::table('pickups')->where('id', $manifest->pickup_id)->first(['pickup_number', 'requested_date', 'confirmed_date']);

        return [
            'manifest_number' => $manifest->manifest_number,
            'pickup_number' => $pickup->pickup_number,
            'pickup_date' => $pickup->confirmed_date ?? $pickup->requested_date,
            'waste_code' => $code->code,
            'waste_label' => json_decode((string) $code->label, true)['fr'] ?? null,
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

        if ($party === SignerParty::Producer) {
            $this->notify->toSitePermission($manifest->site_id, 'documents.sign', 'document.signature_requested', $params, '/compliance/manifests/'.$manifest->ulid);

            return;
        }

        $companyId = $party === SignerParty::Transporter ? $manifest->transporter_company_id : $manifest->receiver_company_id;
        $this->notify->toCompanyPermission((int) $companyId, 'provider.manifests.sign', 'document.signature_requested', $params, '/provider/manifests/'.$manifest->ulid);
    }
}
