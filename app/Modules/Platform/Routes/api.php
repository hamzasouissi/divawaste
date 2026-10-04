<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Resources\AuditLogResource;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/*
 * Back office of Diva Software as operator of the SaaS (M0-03, M0-04, M0-06, M7-02): what needs a decision,
 * the plans and their reference prices, the invoices of every client, and the trail of what the platform did.
 * No tenant context: every route is guarded by a platform permission, and none exposes operational data of a
 * client (lots, pickups, documents).
 */
Route::middleware('auth:sanctum')->prefix('platform')->group(function () {
    $allow = fn (Request $request, string ...$permissions) => abort_unless(collect($permissions)->contains(fn (string $permission) => $request->user()->hasPermissionTo($permission)), 403);

    /** Entities whose changes are platform business: registrations, accreditations, subscriptions, money, reference data. */
    $platformEntities = ['companies', 'provider_accreditations', 'company_subscriptions', 'invoices', 'payments', 'waste_catalog_items', 'subscription_plans', 'roles'];

    Route::get('overview', function (Request $request) use ($allow, $platformEntities) {
        $allow($request, 'platform.companies.review', 'platform.support.view');
        $companies = DB::table('companies')->selectRaw('status, company_type, count(*) as total')->groupBy('status', 'company_type')->get();
        $count = fn (?string $status = null, ?string $type = null) => (int) $companies
            ->when($status, fn ($rows) => $rows->where('status', $status))->when($type, fn ($rows) => $rows->where('company_type', $type))->sum('total');
        // A formula asked at registration or from "Mon abonnement", waiting for the platform to activate it at the agreed price.
        $requests = fn () => DB::table('companies')->where('status', 'active')->where('company_type', 'industrial')->where('settings', 'like', '%requested_plan%')
            ->orderBy('legal_name')->get(['ulid', 'legal_name', 'settings'])->map(fn ($row) => ['ulid' => $row->ulid, 'legal_name' => $row->legal_name,
                'plan' => json_decode((string) $row->settings, true)['requested_plan'] ?? null, 'sites' => json_decode((string) $row->settings, true)['requested_sites'] ?? null])
            ->filter(fn (array $row) => $row['plan'] !== null && $row['plan'] !== 'trial');
        $subscriptions = DB::table('company_subscriptions')->where('is_current', true)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json(['data' => [
            'companies' => [
                'total' => $count(), 'industrial' => $count(null, 'industrial'), 'provider' => $count(null, 'provider'),
                'active' => $count('active'), 'pending_approval' => $count('pending_approval'), 'pending_verification' => $count('pending_verification'),
                'suspended' => $count('suspended'), 'rejected' => $count('rejected'),
            ],
            'accreditations' => [
                'pending' => DB::table('provider_accreditations')->whereNull('deleted_at')->where('review_status', 'pending')->count(),
                'expiring' => DB::table('provider_accreditations')->whereNull('deleted_at')->where('review_status', 'approved')
                    ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])->count(),
                'expired' => DB::table('provider_accreditations')->whereNull('deleted_at')->where('review_status', 'approved')->where('expires_on', '<', now()->toDateString())->count(),
            ],
            'subscriptions' => [
                'trialing' => (int) ($subscriptions['trialing'] ?? 0), 'active' => (int) ($subscriptions['active'] ?? 0),
                'past_due' => (int) ($subscriptions['past_due'] ?? 0), 'suspended' => (int) ($subscriptions['suspended'] ?? 0),
                'plan_requests' => $requests()->count(),
            ],
            'billing' => [
                'payments_pending' => DB::table('payments')->where('status', 'pending')->count(),
                'invoices_open' => DB::table('invoices')->whereIn('status', ['issued', 'partially_paid', 'overdue'])->count(),
                'invoices_overdue' => DB::table('invoices')->whereIn('status', ['issued', 'partially_paid', 'overdue'])->where('due_date', '<', now()->toDateString())->count(),
            ],
            'pending_companies' => DB::table('companies')->where('status', 'pending_approval')->orderBy('submitted_at')->limit(8)
                ->get(['ulid', 'legal_name', 'company_type', 'city', 'submitted_at']),
            'plan_requests' => $requests()->take(8)->values(),
            'recent_activity' => DB::table('audit_logs as a')->leftJoin('companies as c', 'c.id', '=', 'a.company_id')->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
                ->whereIn('a.auditable_type', $platformEntities)->whereNotIn('a.event', ['created', 'updated'])->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(8)
                ->get(['a.event', 'a.auditable_type as entity', 'a.created_at as occurred_at', 'c.legal_name as company', 'u.first_name', 'u.last_name'])
                ->map(fn ($row) => ['event' => $row->event, 'entity' => $row->entity, 'occurred_at' => now()->parse($row->occurred_at)->toIso8601ZuluString(),
                    'company' => $row->company, 'actor' => trim("{$row->first_name} {$row->last_name}") ?: null]),
        ]]);
    })->name('platform.overview');

    /*
     * Plans (M0-03). The reference price is the price proposed when a plan is activated for a client; the price
     * really invoiced is the one confirmed at activation and frozen on the subscription.
     */
    $plans = function () {
        $prices = DB::table('subscription_plan_prices')->where('is_active', true)->whereNull('valid_to')->orderBy('valid_from')
            ->get(['subscription_plan_id', 'currency_code', 'billing_interval', 'component', 'unit_amount'])->groupBy('subscription_plan_id');
        $usage = DB::table('company_subscriptions')->where('is_current', true)->selectRaw('subscription_plan_id, count(*) as total')->groupBy('subscription_plan_id')->pluck('total', 'subscription_plan_id');

        return DB::table('subscription_plans')->orderBy('sort_order')->get()->map(fn ($plan) => [
            'code' => $plan->code, 'name' => json_decode((string) $plan->name, true), 'description' => json_decode((string) ($plan->description ?? 'null'), true),
            'billing_model' => $plan->billing_model, 'is_trial' => (bool) $plan->is_trial, 'trial_days' => $plan->trial_days,
            'is_public' => (bool) $plan->is_public, 'is_active' => (bool) $plan->is_active, 'subscribers' => (int) ($usage[$plan->id] ?? 0),
            'prices' => ($prices[$plan->id] ?? collect())->map(fn ($price) => ['currency_code' => $price->currency_code, 'billing_interval' => $price->billing_interval,
                'component' => $price->component, 'unit_amount' => $price->unit_amount])->values(),
        ])->values();
    };

    Route::get('plans', function (Request $request) use ($allow, $plans) {
        $allow($request, 'platform.plans.manage', 'platform.invoices.manage');

        return response()->json(['data' => $plans(), 'meta' => [
            'currencies' => DB::table('countries')->where('is_active', true)->distinct()->orderBy('currency_code')->pluck('currency_code'),
        ]]);
    })->name('platform.plans.index');

    Route::put('plans/{code}', function (Request $request, string $code) use ($allow, $plans) {
        $allow($request, 'platform.plans.manage');
        $plan = DB::table('subscription_plans')->where('code', $code)->first();
        abort_if($plan === null, 404);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_public' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'trial_days' => [Rule::requiredIf((bool) $plan->is_trial), 'nullable', 'integer', 'between:1,365'],
            'currency_code' => ['nullable', 'string', Rule::exists('currencies', 'code')],
            'monthly_amount' => ['nullable', 'decimal:0,3', 'gte:0'],
            'yearly_amount' => ['nullable', 'decimal:0,3', 'gte:0'],
        ]);

        DB::transaction(function () use ($plan, $input) {
            $name = ['fr' => $input['name']] + (json_decode((string) $plan->name, true) ?? []);
            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'name' => json_encode(['fr' => $input['name']] + $name), 'description' => ($input['description'] ?? null) === null ? null : json_encode(['fr' => $input['description']]),
                'is_public' => $input['is_public'], 'is_active' => $input['is_active'],
                'trial_days' => $plan->is_trial ? $input['trial_days'] : null, 'updated_at' => now(),
            ]);

            if (! $plan->is_trial && ($input['currency_code'] ?? null) !== null) {
                // A per-volume plan is invoiced as a flat amount per period (tonnage is not metered in the MVP).
                $component = $plan->billing_model === 'per_volume' ? 'base_fee' : 'per_site';
                foreach (['monthly' => $input['monthly_amount'] ?? null, 'yearly' => $input['yearly_amount'] ?? null] as $interval => $amount) {
                    $current = DB::table('subscription_plan_prices')->where('subscription_plan_id', $plan->id)->where('currency_code', $input['currency_code'])
                        ->where('billing_interval', $interval)->where('component', $component)->where('is_active', true)->whereNull('valid_to');
                    if ($amount !== null && (string) $current->value('unit_amount') === number_format((float) $amount, 3, '.', '')) {
                        continue;
                    }
                    // A price is never rewritten: the old one is closed, so past quotations stay explainable.
                    $current->update(['valid_to' => now()->toDateString(), 'is_active' => false, 'updated_at' => now()]);
                    if ($amount !== null && (float) $amount > 0) {
                        DB::table('subscription_plan_prices')->where('subscription_plan_id', $plan->id)->where('currency_code', $input['currency_code'])
                            ->where('billing_interval', $interval)->where('component', $component)->where('tier_min', 0)->whereDate('valid_from', now()->toDateString())->delete();
                        DB::table('subscription_plan_prices')->insert([
                            'subscription_plan_id' => $plan->id, 'currency_code' => $input['currency_code'], 'billing_interval' => $interval, 'component' => $component,
                            'unit_amount' => $amount, 'tier_min' => 0, 'valid_from' => now()->toDateString(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }

            AuditLogger::record(['event' => 'plan_updated', 'auditable_type' => 'subscription_plans', 'auditable_id' => $plan->id,
                'old_values' => ['is_public' => (bool) $plan->is_public, 'is_active' => (bool) $plan->is_active],
                'new_values' => array_filter($input, fn ($value) => $value !== null), 'tags' => ['platform_billing']]);
        });

        return response()->json(['data' => $plans()->firstWhere('code', $code)]);
    })->name('platform.plans.update');

    /* Billing follow-up: every invoice of every client, with what is still due. */
    Route::get('invoices', function (Request $request) use ($allow) {
        $allow($request, 'platform.invoices.manage', 'platform.payments.validate');
        $filter = $request->validate([
            'filter.status' => ['nullable', Rule::in(['issued', 'partially_paid', 'paid', 'overdue', 'cancelled'])],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])['filter'] ?? [];

        $invoices = DB::table('invoices as i')->join('companies as c', 'c.id', '=', 'i.company_id')->where('i.status', '!=', 'draft')
            ->when($filter['status'] ?? null, fn ($query, $value) => $query->where('i.status', $value))
            ->when($filter['search'] ?? null, fn ($query, $value) => $query->where(fn ($q) => $q
                ->where('c.legal_name', 'like', '%'.addcslashes($value, '%_\\').'%')->orWhere('i.invoice_number', 'like', '%'.addcslashes($value, '%_\\').'%')))
            ->orderByDesc('i.issue_date')->orderByDesc('i.id')
            ->paginate(min((int) $request->integer('per_page', 25), 100), ['i.id', 'i.ulid', 'i.invoice_number', 'i.status', 'i.issue_date', 'i.due_date', 'i.period_start', 'i.period_end',
                'i.currency_code', 'i.total_amount', 'i.amount_paid', 'c.ulid as company', 'c.legal_name']);
        $pending = DB::table('payments')->whereIn('invoice_id', collect($invoices->items())->pluck('id'))->where('status', 'pending')->pluck('invoice_id')->flip();

        return response()->json([
            'data' => collect($invoices->items())->map(fn ($row) => [
                'ulid' => $row->ulid, 'invoice_number' => $row->invoice_number, 'status' => $row->status, 'issue_date' => $row->issue_date, 'due_date' => $row->due_date,
                'period_start' => $row->period_start, 'period_end' => $row->period_end, 'currency_code' => $row->currency_code, 'total_amount' => $row->total_amount,
                'amount_paid' => $row->amount_paid, 'company' => $row->company, 'legal_name' => $row->legal_name, 'pending_payment' => $pending->has($row->id),
                'is_late' => in_array($row->status, ['issued', 'partially_paid', 'overdue'], true) && $row->due_date < now()->toDateString(),
            ]),
            'meta' => ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage(), 'per_page' => $invoices->perPage(), 'total' => $invoices->total()],
        ]);
    })->name('platform.invoices.index');

    /* Platform activity (M7-02): who at Diva Software decided what, and what clients did to their account with the platform. */
    Route::get('activity', function (Request $request) use ($allow, $platformEntities) {
        $allow($request, 'platform.support.view');
        $filter = $request->validate([
            'filter.entity' => ['nullable', Rule::in($platformEntities)],
            'filter.event' => ['nullable', 'string', 'max:40'],
            'filter.company' => ['nullable', 'string', 'ulid'],
            'filter.from' => ['nullable', 'date'],
            'filter.to' => ['nullable', 'date', 'after_or_equal:filter.from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])['filter'] ?? [];

        $logs = AuditLog::query()->whereIn('auditable_type', $platformEntities)
            ->when($filter['entity'] ?? null, fn ($query, $value) => $query->where('auditable_type', $value))
            ->when($filter['event'] ?? null, fn ($query, $value) => $query->where('event', $value))
            ->when($filter['company'] ?? null, fn ($query, $value) => $query->where('company_id', DB::table('companies')->where('ulid', strtoupper($value))->value('id') ?? 0))
            ->when($filter['from'] ?? null, fn ($query, $value) => $query->where('created_at', '>=', $value))
            ->when($filter['to'] ?? null, fn ($query, $value) => $query->where('created_at', '<', now()->parse($value)->addDay()->startOfDay()))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        $actors = DB::table('users')->whereIn('id', $logs->getCollection()->pluck('actor_user_id')->filter()->unique())->get(['id', 'ulid', 'first_name', 'last_name'])->keyBy('id');
        $companies = DB::table('companies')->whereIn('id', $logs->getCollection()->pluck('company_id')->filter()->unique())->get(['id', 'ulid', 'legal_name'])->keyBy('id');
        $logs->getCollection()->each(fn (AuditLog $log) => $log->setRelation('actor', $actors->get($log->actor_user_id)));

        return response()->json([
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => (new AuditLogResource($log))->resolve($request) + [
                'company' => ($company = $companies->get($log->company_id)) === null ? null : ['ulid' => $company->ulid, 'legal_name' => $company->legal_name],
            ])->values(),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'per_page' => $logs->perPage(), 'total' => $logs->total()],
        ]);
    })->name('platform.activity.index');
});
