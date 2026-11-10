<?php

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Contracts\Actions\ManageContracts;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Support\ContractTerms;
use App\Modules\Providers\Models\ExternalPartner;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/*
 * Contracts with receivers and transporters: prices per waste type, fees per pickup and per month, how many pickups
 * and when, for how long. The company writes and manages them; a provider of the platform reads the ones it is party to.
 */
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $industrial = fn () => app(TenantContext::class)->companyType() === CompanyType::Industrial;
    $read = fn (Request $request) => abort_unless($industrial() ? $request->user()->can('contracts.view') : $request->user()->can('provider.contracts.view'), 403);
    $manage = fn (Request $request) => abort_unless($industrial() && $request->user()->can('contracts.manage'), 403);
    // Written as text: a rule object held by the routes does not survive the route cache.
    $direction = 'in:revenue,cost';
    $rules = fn (bool $creating) => [
        // The other party: a provider of the platform, or a partner without an account. Exactly one of the two.
        'provider' => [$creating ? 'required_without:external_partner' : 'sometimes', 'prohibits:external_partner', 'nullable', 'string',
            Rule::exists('companies', 'ulid')->where('company_type', CompanyType::Provider->value)->where('status', 'active')],
        'external_partner' => ['nullable', 'string', new TenantExists(ExternalPartner::class, fn ($q) => $q->where('is_active', true)->whereNull('linked_company_id'))],
        // receiver = collects, recycles or sorts the waste; transporter = carries it.
        'party_role' => [$creating ? 'required' : 'sometimes', Rule::in(['receiver', 'transporter'])],
        'reference' => ['nullable', 'string', 'max:100'],
        'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
        // null = every site of the company.
        'site' => ['nullable', 'string', new TenantExists(Site::class)],
        'starts_on' => [$creating ? 'required' : 'sometimes', 'date'],
        'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        'frequency' => ['sometimes', Rule::in(Contract::FREQUENCIES)],
        'pickups_per_period' => ['nullable', 'integer', 'between:1,31'],
        'weekdays' => ['nullable', 'array'],
        'weekdays.*' => ['string', 'distinct', Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
        'planned_pickups' => ['nullable', 'integer', 'between:1,10000'],
        'pickup_fee' => ['nullable', 'decimal:0,3', 'gte:0', 'lte:999999999'],
        'pickup_fee_direction' => ['sometimes', $direction],
        'monthly_fee' => ['nullable', 'decimal:0,3', 'gte:0', 'lte:999999999'],
        'monthly_fee_direction' => ['sometimes', $direction],
        'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
        'notes' => ['nullable', 'string', 'max:5000'],
        // A price per waste type; a line without waste type is the price of every other one.
        'prices' => ['sometimes', 'array', 'max:100'],
        'prices.*.waste_type' => ['nullable', 'string', new TenantExists(WasteType::class)],
        'prices.*.pricing_mode' => ['required', Rule::in(['per_kg', 'per_tonne', 'flat'])],
        'prices.*.unit_price' => ['required', 'decimal:0,3', 'gte:0', 'lte:999999999'],
        'prices.*.direction' => ['required', $direction],
        // Charges beside the prices: as many as the contract has, each with its name, amount, when it is due, who pays.
        'charges' => ['sometimes', 'array', 'max:30'],
        'charges.*.label' => ['required', 'string', 'max:120'],
        'charges.*.amount' => ['required', 'decimal:0,3', 'gte:0', 'lte:999999999'],
        'charges.*.basis' => ['required', Rule::in(['per_pickup', 'per_tonne', 'per_month', 'once'])],
        'charges.*.direction' => ['required', $direction],
    ];
    // Only what the request carries: a field left out of an update keeps its value.
    $data = function (array $input): array {
        $out = array_diff_key($input, array_flip(['provider', 'external_partner', 'site', 'prices']));
        if (array_key_exists('provider', $input) || array_key_exists('external_partner', $input)) {
            $out['provider_company_id'] = isset($input['provider']) ? (int) Company::query()->where('ulid', strtoupper($input['provider']))->value('id') : null;
            $out['external_partner_id'] = isset($input['external_partner']) ? (int) ExternalPartner::query()->where('ulid', strtoupper($input['external_partner']))->value('id') : null;
        }
        if (array_key_exists('site', $input)) {
            $out['site_id'] = $input['site'] === null ? null : (int) Site::query()->where('ulid', strtoupper($input['site']))->value('id');
        }
        if (array_key_exists('prices', $input)) {
            $out['prices'] = array_map(fn (array $price) => ['waste_type_id' => isset($price['waste_type']) ? (int) WasteType::query()->where('ulid', strtoupper($price['waste_type']))->value('id') : null,
                'pricing_mode' => $price['pricing_mode'], 'unit_price' => (string) $price['unit_price'], 'direction' => $price['direction']], $input['prices']);
        }

        return $out;
    };

    Route::get('contracts', function (Request $request) use ($read) {
        $read($request);
        $input = $request->validate([
            'filter.state' => ['nullable', 'array'], 'filter.state.*' => [Rule::in(['draft', 'upcoming', 'active', 'expired', 'terminated'])],
            'filter.party_role' => ['nullable', Rule::in(['receiver', 'transporter'])],
        ]);
        $filter = $input['filter'] ?? [];
        $rows = Contract::query()->when($filter['party_role'] ?? null, fn ($q, $role) => $q->where('party_role', $role))
            ->orderByRaw("FIELD(status, 'active', 'draft', 'terminated')")->orderByDesc('starts_on')->limit(500)->get()
            ->map(fn (Contract $contract) => ContractTerms::present($contract, false))
            ->when($filter['state'] ?? null, fn ($rows, $states) => $rows->filter(fn (array $row) => in_array($row['state'], $states, true)));

        return ['data' => $rows->values()];
    })->name('contracts.index');

    Route::post('contracts', function (Request $request, ManageContracts $contracts) use ($manage, $rules, $data) {
        $manage($request);
        $contract = $contracts->create($data($request->validate($rules(true))), $request->user());

        return response()->json(['data' => ContractTerms::present($contract->refresh())], 201);
    })->name('contracts.store');

    Route::get('contracts/{contract}', function (Request $request, Contract $contract) use ($read) {
        $read($request);

        return ['data' => ContractTerms::present($contract)];
    })->name('contracts.show');

    // The terms; the party and its role only while it is a draft. Changes apply to the pickups received from then on.
    Route::put('contracts/{contract}', function (Request $request, Contract $contract, ManageContracts $contracts) use ($manage, $rules, $data) {
        $manage($request);

        return ['data' => ContractTerms::present($contracts->update($contract, $data($request->validate($rules(false)))))];
    })->name('contracts.update');

    // From now on the contract applies to the pickups of its party, role and site between its dates.
    Route::post('contracts/{contract}/activate', function (Request $request, Contract $contract, ManageContracts $contracts) use ($manage) {
        $manage($request);

        return ['data' => ContractTerms::present($contracts->activate($contract))];
    })->name('contracts.activate');

    Route::post('contracts/{contract}/terminate', function (Request $request, Contract $contract, ManageContracts $contracts) use ($manage) {
        $manage($request);
        $input = $request->validate(['reason' => ['nullable', 'string', 'max:500'], 'on' => ['nullable', 'date']]);

        return ['data' => ContractTerms::present($contracts->terminate($contract, $input['reason'] ?? null, $input['on'] ?? null))];
    })->name('contracts.terminate');
});
