<?php

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\WasteType;
use App\Modules\Catalog\Support\UnitConversion;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Models\StoredFile;
use App\Modules\Common\Support\FileStorage;
use App\Modules\Common\Support\Measure;
use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Pickups\Support\ProviderRequirements;
use App\Modules\Providers\Models\ExternalPartner;
use App\Modules\Providers\Models\PartnerRequirement;
use App\Modules\Providers\Models\ProviderAcceptedWaste;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderPartnership;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Support\ProviderDirectory;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

$accreditationData = fn (stdClass $row) => [
    'ulid' => $row->ulid, 'type' => $row->type, 'name' => json_decode((string) $row->name, true),
    'covers_hazardous' => (bool) $row->covers_hazardous, 'reference_number' => $row->reference_number,
    'issuing_authority' => $row->issuing_authority, 'valid_from' => $row->valid_from, 'expires_on' => $row->expires_on,
    'review_status' => $row->review_status, 'rejection_reason' => $row->rejection_reason,
    'expired' => $row->expires_on < now()->toDateString(),
];
$accreditationColumns = ['a.ulid', 't.code as type', 't.name', 't.covers_hazardous', 'a.reference_number', 'a.issuing_authority',
    'a.valid_from', 'a.expires_on', 'a.review_status', 'a.rejection_reason'];
$streamFile = fn (StoredFile $file) => Storage::disk($file->disk)->response($file->path, $file->original_name, [
    'Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
]);

Route::middleware(['auth:sanctum', 'tenant'])->group(function () use ($accreditationData, $accreditationColumns, $streamFile) {
    $allow = fn (Request $request, string $permission) => abort_unless($request->user()->can($permission), 403);

    /*
     * Industrial side: directory (M4-01, M4-02) and partnerships.
     */
    Route::get('providers/directory', function (Request $request, ProviderDirectory $directory, TenantContext $context) use ($allow) {
        $allow($request, 'providers.view');
        $input = $request->validate([
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.channel' => ['nullable', 'string', Rule::exists('treatment_channels', 'code')],
            'filter.partners_only' => ['nullable', 'boolean'],
            'filter.hazardous' => ['nullable', 'boolean'],
        ])['filter'] ?? [];

        return ['data' => $directory->list($context->companyId(), [
            'search' => $input['search'] ?? null, 'channel' => $input['channel'] ?? null,
            'partners_only' => (bool) ($input['partners_only'] ?? false), 'hazardous' => (bool) ($input['hazardous'] ?? false),
        ])];
    })->name('providers.directory');

    // The accreditation document itself, for an industrial checking a published provider.
    Route::get('providers/accreditations/{accreditation}/file', function (Request $request, string $accreditation) use ($allow, $streamFile) {
        $allow($request, 'providers.view');
        $row = DB::table('provider_accreditations as a')->join('provider_profiles as p', 'p.company_id', '=', 'a.company_id')
            ->where('a.ulid', strtoupper($accreditation))->where('a.review_status', 'approved')->whereNull('a.deleted_at')->where('p.is_published', true)
            ->first(['a.stored_file_id', 'a.company_id']);
        abort_if($row === null, 404);

        return $streamFile(StoredFile::query()->where('company_id', $row->company_id)->findOrFail($row->stored_file_id));
    })->name('providers.accreditations.file');

    Route::post('provider-partnerships', function (Request $request) use ($allow) {
        $allow($request, 'providers.partnerships.manage');
        $ulid = strtoupper((string) $request->validate([
            'provider' => ['required', 'string', Rule::exists('companies', 'ulid')->where('company_type', CompanyType::Provider->value)->where('status', 'active')],
        ])['provider']);

        $partnership = ProviderPartnership::query()->firstOrCreate(
            ['provider_company_id' => Company::query()->where('ulid', $ulid)->value('id')],
            ['status' => 'active', 'started_at' => now(), 'created_by_user_id' => $request->user()->id],
        );
        if ($partnership->status !== 'active') {
            $partnership->forceFill(['status' => 'active', 'ended_at' => null])->save();
        }

        return response()->json(['data' => ['ulid' => $partnership->ulid, 'provider' => $ulid, 'status' => $partnership->status]], 201);
    })->name('provider-partnerships.store');

    Route::put('provider-partnerships/{partnership}', function (Request $request, ProviderPartnership $partnership) use ($allow) {
        $allow($request, 'providers.partnerships.manage');
        $input = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'ended'])],
            'internal_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $partnership->forceFill($input + ['ended_at' => $input['status'] === 'ended' ? now() : null])->save();

        return response()->json(['data' => ['ulid' => $partnership->ulid, 'status' => $partnership->status]]);
    })->name('provider-partnerships.update');

    /*
     * Partners without an account on the platform: a transporter or a receiver the company records itself.
     */
    // Providers any industrial can already see in the directory: naming one here reveals nothing new.
    $publishedProviders = fn () => DB::table('companies as c')->join('provider_profiles as p', 'p.company_id', '=', 'c.id')
        ->where('c.company_type', CompanyType::Provider->value)->where('c.status', 'active')->where('p.is_published', true);
    $partnerCard = fn (ExternalPartner $partner) => [
        'ulid' => $partner->ulid, 'legal_name' => $partner->legal_name, 'tax_id' => $partner->tax_id,
        'address_line1' => $partner->address_line1, 'postal_code' => $partner->postal_code, 'city' => $partner->city,
        'phone' => $partner->phone, 'email' => $partner->email, 'contact_name' => $partner->contact_name,
        'roles' => array_keys(array_filter(['transporter' => $partner->is_transporter, 'recycler' => $partner->is_recycler, 'eliminator' => $partner->is_eliminator])),
        'accreditation_reference' => $partner->accreditation_reference,
        'accreditation_expires_on' => $partner->accreditation_expires_on?->toDateString(),
        'covers_hazardous' => $partner->covers_hazardous, 'notes' => $partner->notes, 'is_active' => $partner->is_active,
        // Once the partner has opened an account, it is tied to it: new pickups go through the platform.
        'linked_company' => $partner->linked_company_id === null ? null
            : (array) DB::table('companies')->where('id', $partner->linked_company_id)->first(['ulid', 'legal_name']),
        // A published provider with the same tax ID: probably this partner, now on the platform.
        'suggested_company' => $partner->linked_company_id !== null || $partner->tax_id === null ? null : $publishedProviders()
            ->whereRaw("REPLACE(UPPER(c.tax_id), ' ', '') = ?", [str_replace(' ', '', mb_strtoupper($partner->tax_id))])->first(['c.ulid', 'c.legal_name']),
    ];
    // What the partner asks of a collection, as the company wrote it down. It is the company's own record: the partner
    // has no account to confirm it with. Once it has one, each line waits for someone to look at it again.
    $partnerData = function (ExternalPartner $partner) use ($partnerCard): array {
        $codes = app(ReferenceCodes::class);
        $rule = fn (object $row): array => [
            'family' => $codes->code('waste_families', $row->waste_family_id === null ? null : (int) $row->waste_family_id),
            // One waste type rather than the whole family: one of the company's own types for what it wrote down, an item
            // of the shared catalogue for what a provider publishes. Its line replaces the family's for its lots unless
            // it comes in addition to it.
            'waste_type' => ($row->waste_type_id ?? null) === null ? null : (array) DB::table('waste_types')->where('id', $row->waste_type_id)->first(['ulid', 'code', 'name']),
            'catalog_item' => $codes->code('waste_catalog_items', ($row->waste_catalog_item_id ?? null) === null ? null : (int) $row->waste_catalog_item_id),
            'adds_to_family' => (bool) ($row->adds_to_family ?? false),
            'channel' => $codes->code('treatment_channels', (int) $row->treatment_channel_id),
            'min_quantity' => $row->min_quantity === null ? null : (string) $row->min_quantity, 'min_unit' => $row->min_unit_code,
            'min_packaging_type' => $codes->code('packaging_types', $row->min_packaging_type_id === null ? null : (int) $row->min_packaging_type_id),
            'required_packaging_type' => $codes->code('packaging_types', $row->required_packaging_type_id === null ? null : (int) $row->required_packaging_type_id),
            'is_blocking' => (bool) $row->is_blocking, 'notes' => $row->notes,
        ];
        $linked = $partner->linked_company_id !== null;
        $users = fn (?int $id) => $id === null ? null : trim((string) DB::table('users')->where('id', $id)->selectRaw("CONCAT(first_name, ' ', last_name) as name")->value('name'));
        $requirements = PartnerRequirement::query()->where('external_partner_id', $partner->id)->orderBy('id')->get()->map(fn (PartnerRequirement $row) => ['ulid' => $row->ulid] + $rule($row) + [
            'contract_reference' => $row->contract_reference,
            // The partner now has an account and nobody has looked at this line since.
            'needs_review' => $linked && ($row->reviewed_at === null || $row->reviewed_at->lt($partner->getAttribute('linked_at'))),
            'reviewed_at' => $row->reviewed_at?->toIso8601ZuluString(),
            'updated_at' => $row->getAttribute('updated_at')?->toIso8601ZuluString(),
            'updated_by' => $users($row->getAttribute('updated_by_user_id') ?? $row->getAttribute('created_by_user_id')),
        ]);

        return $partnerCard($partner) + [
            'requirements' => $requirements, 'requirements_to_review' => $requirements->where('needs_review', true)->count(),
            // What the provider itself publishes now that it has an account, to compare with: its word, not the company's notes.
            'provider_requirements' => ! $linked ? [] : array_map($rule, DB::table('provider_accepted_wastes')->where('company_id', $partner->linked_company_id)->where('is_active', true)
                ->where(fn ($query) => $query->where('min_quantity', '>', 0)->orWhereNotNull('required_packaging_type_id'))->orderBy('id')->get()->all()),
        ];
    };
    $partnerAttributes = function (Request $request) {
        $input = $request->validate([
            'legal_name' => ['required', 'string', 'max:191'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(['transporter', 'recycler', 'eliminator'])],
            'accreditation_reference' => ['nullable', 'string', 'max:100'],
            'accreditation_expires_on' => ['nullable', 'date'],
            'covers_hazardous' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $roles = $input['roles'];

        return ['is_transporter' => in_array('transporter', $roles, true), 'is_recycler' => in_array('recycler', $roles, true),
            'is_eliminator' => in_array('eliminator', $roles, true), 'covers_hazardous' => (bool) ($input['covers_hazardous'] ?? false)]
            + array_diff_key($input, ['roles' => true]);
    };

    Route::get('external-partners', function (Request $request) use ($allow, $partnerData) {
        $allow($request, 'providers.view');

        return ['data' => array_map($partnerData, ExternalPartner::query()
            ->when(! $request->boolean('with_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('legal_name')->get()->all())];
    })->name('external-partners.index');

    Route::post('external-partners', function (Request $request) use ($allow, $partnerData, $partnerAttributes) {
        $allow($request, 'providers.partnerships.manage');

        return response()->json(['data' => $partnerData(ExternalPartner::query()->create($partnerAttributes($request) + ['created_by_user_id' => $request->user()->id]))], 201);
    })->name('external-partners.store');

    // Never deleted: pickups and slips keep pointing to it. A partner the company stops using is deactivated.
    Route::put('external-partners/{partner}', function (Request $request, ExternalPartner $partner) use ($allow, $partnerData, $partnerAttributes) {
        $allow($request, 'providers.partnerships.manage');
        $partner->update($partnerAttributes($request));

        return response()->json(['data' => $partnerData($partner)]);
    })->name('external-partners.update');

    // What the partner asks of a collection, as the company knows it. The whole list is sent: a line with its identifier
    // is changed, one without is new, one left out is removed — each of these is in the audit trail with its author.
    Route::put('external-partners/{partner}/requirements', function (Request $request, ExternalPartner $partner, ReferenceCodes $codes) use ($allow, $partnerData) {
        $allow($request, 'providers.partnerships.manage');
        $packaging = Rule::exists('packaging_types', 'code')->where('is_active', true);
        $input = $request->validate([
            'requirements' => ['present', 'array', 'max:100'],
            'requirements.*.ulid' => ['nullable', 'string', 'ulid'],
            'requirements.*.family' => ['required', 'string', Rule::exists('waste_families', 'code')],
            // One of the company's own waste types of that family, when the requirement is about it alone.
            'requirements.*.waste_type' => ['nullable', 'string', 'ulid', new TenantExists(WasteType::class)],
            'requirements.*.adds_to_family' => ['sometimes', 'boolean'],
            'requirements.*.channel' => ['required', 'string', Rule::exists('treatment_channels', 'code')->where('is_active', true)],
            // A line asks something: a minimum, a packaging, or both.
            'requirements.*.min_quantity' => ['nullable', 'required_without:requirements.*.required_packaging_type', 'decimal:0,3', 'gt:0'],
            'requirements.*.min_unit' => ['nullable', 'required_with:requirements.*.min_quantity', 'string', Rule::in(Measure::UNITS)],
            'requirements.*.min_packaging_type' => ['nullable', 'required_if:requirements.*.min_unit,package', 'string', $packaging],
            'requirements.*.required_packaging_type' => ['nullable', 'string', $packaging],
            // Unless said otherwise a requirement warns: refusing a request on the company's own notes is a choice.
            'requirements.*.is_blocking' => ['sometimes', 'boolean'],
            'requirements.*.contract_reference' => ['nullable', 'string', 'max:191'],
            'requirements.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        $types = WasteType::query()->whereIn('ulid', collect($input['requirements'])->pluck('waste_type')->filter()->map(fn ($ulid) => strtoupper((string) $ulid))->all())
            ->get()->keyBy('ulid');
        $errors = [];
        foreach ($input['requirements'] as $index => $line) {
            $type = isset($line['waste_type']) ? $types->get(strtoupper($line['waste_type'])) : null;
            if ($type === null) {
                continue;
            }
            if ((int) $type->waste_family_id !== (int) $codes->id('waste_families', $line['family'])) {
                $errors["requirements.{$index}.waste_type"] = [__('validation.custom.requirement.family')];
            }
            // A minimum in pieces or in volume of a type that is neither counted that way nor convertible could never be verified.
            $unit = $line['min_unit'] ?? null;
            if ($unit !== null && ! Measure::suits($unit, $codes->code('units', $type->default_unit_id), $type->density_kg_m3 === null ? null : (string) $type->density_kg_m3,
                $type->unit_weight_kg === null ? null : (string) $type->unit_weight_kg)) {
                $errors["requirements.{$index}.min_unit"] = [__('validation.custom.requirement.unit', ['type' => $type->name, 'unit' => $unit])];
            }
        }
        foreach (ProviderRequirements::duplicates(array_map(fn (array $line) => ['waste_type' => isset($line['waste_type']) ? strtoupper($line['waste_type']) : null] + $line, $input['requirements']), 'waste_type') as $index => $what) {
            $errors["requirements.{$index}"] = [__('validation.custom.requirement.duplicate', ['what' => $what])];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($input, $partner, $codes, $request, $types) {
            $existing = PartnerRequirement::query()->where('external_partner_id', $partner->id)->lockForUpdate()->get()->keyBy('ulid');
            $kept = [];
            foreach ($input['requirements'] as $index => $line) {
                $minimum = $line['min_quantity'] ?? null;
                $unit = $minimum === null ? null : (string) $line['min_unit'];
                $values = [
                    'waste_family_id' => $codes->id('waste_families', $line['family']), 'treatment_channel_id' => $codes->id('treatment_channels', $line['channel']),
                    'waste_type_id' => isset($line['waste_type']) ? $types->get(strtoupper($line['waste_type']))?->id : null,
                    'adds_to_family' => isset($line['waste_type']) && (bool) ($line['adds_to_family'] ?? false),
                    'min_quantity' => $minimum, 'min_unit_code' => $unit,
                    'min_packaging_type_id' => $unit === Measure::PACKAGE ? $codes->id('packaging_types', $line['min_packaging_type'] ?? null) : null,
                    'required_packaging_type_id' => $codes->id('packaging_types', $line['required_packaging_type'] ?? null),
                    'is_blocking' => (bool) ($line['is_blocking'] ?? false),
                    'contract_reference' => $line['contract_reference'] ?? null, 'notes' => $line['notes'] ?? null,
                ];
                $row = isset($line['ulid']) ? $existing->get(strtoupper($line['ulid'])) : null;
                if (isset($line['ulid']) && $row === null) {
                    throw ValidationException::withMessages(["requirements.{$index}.ulid" => [__('validation.exists', ['attribute' => 'ulid'])]]);
                }
                if ($row === null) {
                    $row = PartnerRequirement::query()->create($values + ['external_partner_id' => $partner->id, 'created_by_user_id' => $request->user()->id]);
                } elseif ($row->fill($values)->isDirty()) {
                    // Someone who rewrites a line of a partner that now has an account has looked at it.
                    $row->fill(['updated_by_user_id' => $request->user()->id] + ($partner->linked_company_id === null ? [] : ['reviewed_at' => now(), 'reviewed_by_user_id' => $request->user()->id]))->save();
                }
                $kept[] = $row->id;
            }
            $existing->reject(fn (PartnerRequirement $row) => in_array($row->id, $kept, true))->each(fn (PartnerRequirement $row) => $row->delete());
        });

        return response()->json(['data' => $partnerData($partner->refresh())]);
    })->name('external-partners.requirements.update');

    // The partner now has an account and may publish what it asks itself. What the company had written down is not
    // replaced: each line is looked at and either kept, as the company's own requirement, or removed.
    Route::post('external-partners/{partner}/requirements/{requirement}/review', function (Request $request, ExternalPartner $partner, PartnerRequirement $requirement) use ($allow, $partnerData) {
        $allow($request, 'providers.partnerships.manage');
        abort_unless((int) $requirement->getAttribute('external_partner_id') === (int) $partner->id, 404);
        $decision = $request->validate(['decision' => ['required', Rule::in(['keep', 'remove'])]])['decision'];
        if ($partner->linked_company_id === null) {
            throw new BusinessRuleViolation('PARTNER_NOT_LINKED', 'This partner has no account: its requirements are simply edited.');
        }
        $decision === 'remove' ? $requirement->delete() : $requirement->fill(['reviewed_at' => now(), 'reviewed_by_user_id' => $request->user()->id])->save();

        return response()->json(['data' => $partnerData($partner->refresh())]);
    })->name('external-partners.requirements.review');

    // The partner has opened an account: tie it to that provider. Past pickups keep the partner as it was recorded    // (their slips were signed by hand); the provider becomes a partner of the platform for the next ones.
    Route::post('external-partners/{partner}/link', function (Request $request, ExternalPartner $partner) use ($allow, $partnerData, $publishedProviders) {
        $allow($request, 'providers.partnerships.manage');
        $ulid = strtoupper((string) $request->validate(['provider' => ['required', 'string', 'ulid']])['provider']);
        $providerId = $publishedProviders()->where('c.ulid', $ulid)->value('c.id');
        if ($providerId === null) {
            throw ValidationException::withMessages(['provider' => [__('validation.exists', ['attribute' => 'provider'])]]);
        }
        if ($partner->linked_company_id !== null) {
            throw new BusinessRuleViolation('PARTNER_ALREADY_LINKED', 'This partner is already tied to an account.');
        }

        DB::transaction(function () use ($partner, $providerId, $request) {
            $partner->forceFill(['linked_company_id' => $providerId, 'linked_at' => now()])->save();
            $partnership = ProviderPartnership::query()->firstOrCreate(['provider_company_id' => $providerId],
                ['status' => 'active', 'started_at' => now(), 'created_by_user_id' => $request->user()->id]);
            if ($partnership->status !== 'active') {
                $partnership->forceFill(['status' => 'active', 'ended_at' => null])->save();
            }
        });

        return response()->json(['data' => $partnerData($partner->refresh())]);
    })->name('external-partners.link');

    /*
     * Provider side: its own profile, accepted waste and accreditations.
     */
    $profileData = function (TenantContext $context, ReferenceCodes $codes) {
        $profile = ProviderProfile::query()->firstOrFail();

        return [
            'roles' => array_keys(array_filter(['collector' => $profile->is_collector, 'transporter' => $profile->is_transporter,
                'recycler' => $profile->is_recycler, 'eliminator' => $profile->is_eliminator])),
            'description' => $profile->description,
            'service_area' => $profile->service_area,
            'public_contact_name' => $profile->public_contact_name,
            'public_contact_email' => $profile->public_contact_email,
            'public_contact_phone' => $profile->public_contact_phone,
            'is_published' => (bool) $profile->is_published,
            'accepted_wastes' => ProviderAcceptedWaste::query()->where('is_active', true)->orderBy('id')->get()->map(fn ($row) => [
                'channel' => $codes->code('treatment_channels', $row->treatment_channel_id),
                'family' => $codes->code('waste_families', $row->waste_family_id),
                'catalog_item' => $codes->code('waste_catalog_items', $row->waste_catalog_item_id),
                // What the provider asks of a collection of this waste: a minimum in its unit (a minimum in packages names
                // the packaging it counts), a packaging, and whether a collection that falls short is refused or warned about.
                'min_quantity' => $row->min_quantity, 'min_unit' => $row->min_unit_code,
                'min_packaging_type' => $codes->code('packaging_types', $row->min_packaging_type_id),
                'required_packaging_type' => $codes->code('packaging_types', $row->required_packaging_type_id),
                'is_blocking' => (bool) $row->is_blocking, 'adds_to_family' => (bool) $row->adds_to_family,
                'min_quantity_kg' => $row->min_quantity_kg, 'notes' => $row->notes,
            ]),
        ];
    };

    Route::get('provider/profile', function (Request $request, TenantContext $context, ReferenceCodes $codes) use ($allow, $profileData) {
        $allow($request, 'provider.profile.manage');

        return ['data' => $profileData($context, $codes)];
    })->name('provider.profile.show');

    Route::put('provider/profile', function (Request $request, TenantContext $context, ReferenceCodes $codes) use ($allow, $profileData) {
        $allow($request, 'provider.profile.manage');
        $input = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(['collector', 'transporter', 'recycler', 'eliminator'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'service_area' => ['nullable', 'string', 'max:500'],
            'public_contact_name' => ['nullable', 'string', 'max:150'],
            'public_contact_email' => ['nullable', 'email', 'max:191'],
            'public_contact_phone' => ['nullable', 'string', 'max:30'],
            'accepted_wastes' => ['present', 'array', 'max:200'],
            'accepted_wastes.*.channel' => ['required', 'string', Rule::exists('treatment_channels', 'code')->where('is_active', true)],
            'accepted_wastes.*.family' => ['nullable', 'required_without:accepted_wastes.*.catalog_item', 'string', Rule::exists('waste_families', 'code')],
            'accepted_wastes.*.catalog_item' => ['nullable', 'string', Rule::exists('waste_catalog_items', 'code')],
            'accepted_wastes.*.min_quantity' => ['nullable', 'decimal:0,3', 'gt:0'],
            'accepted_wastes.*.min_unit' => ['nullable', 'required_with:accepted_wastes.*.min_quantity', 'string', Rule::in(Measure::UNITS)],
            // A minimum in packages says which packaging it counts.
            'accepted_wastes.*.min_packaging_type' => ['nullable', 'required_if:accepted_wastes.*.min_unit,package', 'string', Rule::exists('packaging_types', 'code')->where('is_active', true)],
            'accepted_wastes.*.required_packaging_type' => ['nullable', 'string', Rule::exists('packaging_types', 'code')->where('is_active', true)],
            'accepted_wastes.*.is_blocking' => ['sometimes', 'boolean'],
            // A line of one waste type (a catalogue item) replaces the line of its family for the lots of that type,
            // unless it comes in addition to it.
            'accepted_wastes.*.adds_to_family' => ['sometimes', 'boolean'],
            // The former name of a minimum in kilograms.
            'accepted_wastes.*.min_quantity_kg' => ['nullable', 'decimal:0,3', 'gte:0'],
            'accepted_wastes.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        $errors = [];
        foreach ($input['accepted_wastes'] as $index => $waste) {
            $item = isset($waste['catalog_item']) ? DB::table('waste_catalog_items')->where('code', $waste['catalog_item'])->value('waste_family_id') : null;
            if ($item !== null && isset($waste['family']) && (int) $item !== (int) $codes->id('waste_families', $waste['family'])) {
                $errors["accepted_wastes.{$index}.catalog_item"] = [__('validation.custom.requirement.family')];
            }
        }
        foreach (ProviderRequirements::duplicates($input['accepted_wastes'], 'catalog_item') as $index => $what) {
            $errors["accepted_wastes.{$index}"] = [__('validation.custom.requirement.duplicate', ['what' => $what])];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($input, $codes) {
            ProviderProfile::query()->firstOrFail()->forceFill([
                'is_collector' => in_array('collector', $input['roles'], true), 'is_transporter' => in_array('transporter', $input['roles'], true),
                'is_recycler' => in_array('recycler', $input['roles'], true), 'is_eliminator' => in_array('eliminator', $input['roles'], true),
            ] + array_intersect_key($input, array_flip(['description', 'service_area', 'public_contact_name', 'public_contact_email', 'public_contact_phone'])))->save();

            ProviderAcceptedWaste::query()->delete();
            foreach ($input['accepted_wastes'] as $waste) {
                $legacy = ($waste['min_quantity'] ?? null) === null && bccomp((string) ($waste['min_quantity_kg'] ?? '0'), '0', 3) > 0;
                $minimum = $legacy ? (string) $waste['min_quantity_kg'] : ($waste['min_quantity'] ?? null);
                $unit = $minimum === null ? null : ($legacy ? 'kg' : (string) $waste['min_unit']);
                $required = $codes->id('packaging_types', $waste['required_packaging_type'] ?? null);
                ProviderAcceptedWaste::query()->create([
                    'min_quantity' => $minimum, 'min_unit_code' => $unit,
                    'min_packaging_type_id' => $unit === Measure::PACKAGE ? $codes->id('packaging_types', $waste['min_packaging_type'] ?? null) : null,
                    'required_packaging_type_id' => $required,
                    // Nothing to refuse on without a requirement.
                    'is_blocking' => ($minimum !== null || $required !== null) && (bool) ($waste['is_blocking'] ?? false),
                    'adds_to_family' => isset($waste['catalog_item']) && (bool) ($waste['adds_to_family'] ?? false),
                    'treatment_channel_id' => $codes->id('treatment_channels', $waste['channel']),
                    'waste_family_id' => $codes->id('waste_families', $waste['family'] ?? null),
                    'waste_catalog_item_id' => $codes->id('waste_catalog_items', $waste['catalog_item'] ?? null),
                    'min_quantity_kg' => $unit !== null && Measure::dimension($unit) === 'mass' ? UnitConversion::convert((string) $minimum, $unit, 'kg') : null, 'notes' => $waste['notes'] ?? null,
                ]);
            }
        });

        return ['data' => $profileData($context, $codes)];
    })->name('provider.profile.update');

    Route::get('provider/accreditations', function (Request $request, TenantContext $context) use ($allow, $accreditationData, $accreditationColumns) {
        $allow($request, 'provider.accreditations.manage');

        return ['data' => DB::table('provider_accreditations as a')->join('accreditation_types as t', 't.id', '=', 'a.accreditation_type_id')
            ->where('a.company_id', $context->companyId())->whereNull('a.deleted_at')->orderByDesc('a.expires_on')
            ->get($accreditationColumns)->map($accreditationData)];
    })->name('provider.accreditations.index');

    // A renewal is a new accreditation: the previous document stays in the history.
    Route::post('provider/accreditations', function (Request $request, TenantContext $context, FileStorage $files) use ($allow) {
        $allow($request, 'provider.accreditations.manage');
        $company = Company::query()->findOrFail($context->companyId());
        $input = $request->validate([
            'type' => ['required', 'string', Rule::exists('accreditation_types', 'code')->where('country_id', $company->country_id)->where('is_active', true)],
            'reference_number' => ['required', 'string', 'max:100'],
            'issuing_authority' => ['nullable', 'string', 'max:191'],
            'valid_from' => ['required', 'date'],
            'expires_on' => ['required', 'date', 'after:today', 'after_or_equal:valid_from'],
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $accreditation = DB::transaction(function () use ($input, $company, $files, $request) {
            $file = $files->store($request->file('file'), $company, 'accreditations', $request->user()->id, $company->id);

            return ProviderAccreditation::query()->create([
                'accreditation_type_id' => DB::table('accreditation_types')->where('country_id', $company->country_id)->where('code', $input['type'])->value('id'),
                'reference_number' => $input['reference_number'], 'issuing_authority' => $input['issuing_authority'] ?? null,
                'valid_from' => $input['valid_from'], 'expires_on' => $input['expires_on'], 'stored_file_id' => $file->id,
            ]);
        });

        return response()->json(['data' => ['ulid' => $accreditation->ulid, 'review_status' => 'pending']], 201);
    })->name('provider.accreditations.store');

    Route::get('provider/accreditations/{accreditation}/file', function (Request $request, ProviderAccreditation $accreditation) use ($allow, $streamFile) {
        $allow($request, 'provider.accreditations.manage');

        return $streamFile(StoredFile::query()->where('company_id', $accreditation->company_id)->findOrFail($accreditation->stored_file_id));
    })->name('provider.accreditations.file');

    // Regulatory document: withdrawn logically, never erased.
    Route::delete('provider/accreditations/{accreditation}', function (Request $request, ProviderAccreditation $accreditation) use ($allow) {
        $allow($request, 'provider.accreditations.manage');
        $accreditation->forceFill(['deletion_reason' => $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null])->save();
        $accreditation->delete();

        return response()->noContent();
    })->name('provider.accreditations.destroy');
});

/*
 * Platform: review of provider accreditations (M0-05, M0-06).
 */
Route::middleware('auth:sanctum')->prefix('platform')->group(function () use ($accreditationData, $accreditationColumns, $streamFile) {
    $allow = fn (Request $request) => abort_unless($request->user()->hasPermissionTo('platform.providers.review'), 403);

    Route::get('accreditations', function (Request $request) use ($allow, $accreditationData, $accreditationColumns) {
        $allow($request);
        $status = $request->validate(['filter.review_status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'revoked'])]])['filter']['review_status'] ?? 'pending';

        return ['data' => DB::table('provider_accreditations as a')->join('accreditation_types as t', 't.id', '=', 'a.accreditation_type_id')
            ->join('companies as c', 'c.id', '=', 'a.company_id')
            ->where('a.review_status', $status)->whereNull('a.deleted_at')->orderBy('a.created_at')->limit(200)
            ->get([...$accreditationColumns, 'c.ulid as company', 'c.legal_name'])
            ->map(fn (object $row) => $accreditationData($row) + ['company' => $row->company, 'legal_name' => $row->legal_name])];
    })->name('platform.accreditations.index');

    Route::get('accreditations/{accreditation}/file', function (Request $request, string $accreditation) use ($allow, $streamFile) {
        $allow($request);
        $row = DB::table('provider_accreditations')->where('ulid', strtoupper($accreditation))->first(['stored_file_id', 'company_id']);
        abort_if($row === null, 404);

        return $streamFile(StoredFile::query()->where('company_id', $row->company_id)->findOrFail($row->stored_file_id));
    })->name('platform.accreditations.file');

    Route::post('accreditations/{accreditation}/review', function (Request $request, string $accreditation, NotifyUsers $notify) use ($allow) {
        $allow($request);
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'revoked'])],
            'reason' => ['nullable', 'required_unless:decision,approved', 'string', 'max:1000'],
        ]);

        $row = DB::transaction(function () use ($accreditation, $input, $request) {
            $row = DB::table('provider_accreditations')->where('ulid', strtoupper($accreditation))->whereNull('deleted_at')->lockForUpdate()->first();
            abort_if($row === null, 404);
            if ($row->review_status === $input['decision']) {
                throw new BusinessRuleViolation('ACCREDITATION_ALREADY_REVIEWED', 'This accreditation already has that status.');
            }
            DB::table('provider_accreditations')->where('id', $row->id)->update([
                'review_status' => $input['decision'], 'reviewed_at' => now(), 'reviewed_by_user_id' => $request->user()->id,
                'rejection_reason' => $input['decision'] === 'approved' ? null : $input['reason'], 'updated_at' => now(),
            ]);
            AuditLogger::record([
                'company_id' => $row->company_id, 'event' => 'accreditation_'.$input['decision'], 'auditable_type' => 'provider_accreditations',
                'auditable_id' => $row->id, 'auditable_ulid' => $row->ulid, 'old_values' => ['review_status' => $row->review_status],
                'new_values' => array_filter(['review_status' => $input['decision'], 'reason' => $input['reason'] ?? null]), 'tags' => ['platform_review'],
            ]);

            return $row;
        });

        $notify->toCompanyPermission((int) $row->company_id, 'provider.accreditations.manage', 'accreditation.reviewed',
            ['reference' => $row->reference_number, 'status' => $input['decision']], '/provider/accreditations');

        return response()->noContent();
    })->name('platform.accreditations.review');
});
