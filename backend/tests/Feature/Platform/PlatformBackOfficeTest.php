<?php

namespace Tests\Feature\Platform;

use App\Modules\Billing\Actions\StartTrial;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Back office of the SaaS operator: overview, plans and reference prices, invoices of every client, platform
 * activity. Reserved to platform staff; a client administrator gets 403 on each route.
 */
class PlatformBackOfficeTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private User $clientAdmin;

    private string $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        Notification::fake();
        config(['billing.seller.tax_id' => '0000000X']);

        $this->company = $this->makeCompany();
        $this->company->forceFill(['tax_id' => '1234567A', 'legal_name' => 'Textile du Cap Bon'])->save();
        $this->clientAdmin = $this->makeMember($this->company, ['client_admin' => null]);
        app(StartTrial::class)->handle($this->company);

        $staff = User::factory()->create();
        $staff->assignRole('super_admin');
        $this->platform = $staff->createToken('t')->plainTextToken;
    }

    public function test_client_users_cannot_reach_the_back_office(): void
    {
        $client = fn () => $this->withToken($this->tokenFor($this->clientAdmin, $this->company));

        $client()->getJson('/api/v1/platform/overview')->assertForbidden();
        $client()->getJson('/api/v1/platform/plans')->assertForbidden();
        $client()->putJson('/api/v1/platform/plans/per_site', ['name' => 'x', 'is_public' => true, 'is_active' => true])->assertForbidden();
        $client()->getJson('/api/v1/platform/invoices')->assertForbidden();
        $client()->getJson('/api/v1/platform/activity')->assertForbidden();
    }

    public function test_overview_counts_what_needs_a_decision(): void
    {
        $pending = $this->makeCompany();
        $pending->forceFill(['status' => 'pending_approval', 'legal_name' => 'Tissages de Ksar Hellal', 'submitted_at' => now()])->save();

        $this->withToken($this->platform)->getJson('/api/v1/platform/overview')->assertOk()
            ->assertJsonPath('data.companies.pending_approval', 1)
            ->assertJsonPath('data.companies.active', 1)
            ->assertJsonPath('data.subscriptions.trialing', 1)
            ->assertJsonPath('data.pending_companies.0.legal_name', 'Tissages de Ksar Hellal');
    }

    public function test_reference_price_is_versioned_and_proposed_for_activation(): void
    {
        $staff = fn () => $this->withToken($this->platform);

        $staff()->getJson('/api/v1/platform/plans')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.code', 'trial');

        $terms = ['name' => 'Abonnement par site', 'is_public' => true, 'is_active' => true, 'currency_code' => 'TND', 'monthly_amount' => '150', 'yearly_amount' => '1500'];
        $staff()->putJson('/api/v1/platform/plans/per_site', $terms)->assertOk()->assertJsonCount(2, 'data.prices');
        // Same price again: nothing new is written.
        $staff()->putJson('/api/v1/platform/plans/per_site', $terms)->assertOk();
        $this->assertSame(2, DB::table('subscription_plan_prices')->count());

        // A new price closes the previous one instead of rewriting it.
        $staff()->putJson('/api/v1/platform/plans/per_site', ['monthly_amount' => '180'] + $terms)->assertOk();
        $plan = collect($staff()->getJson('/api/v1/platform/plans')->json('data'))->firstWhere('code', 'per_site');
        $this->assertSame('180.000', collect($plan['prices'])->firstWhere('billing_interval', 'monthly')['unit_amount']);
        $this->assertSame(1, DB::table('subscription_plan_prices')->where('billing_interval', 'monthly')->where('is_active', true)->count());

        $staff()->putJson('/api/v1/platform/plans/trial', ['name' => 'Essai gratuit', 'is_public' => true, 'is_active' => true])->assertUnprocessable();
        $staff()->putJson('/api/v1/platform/plans/trial', ['name' => 'Essai gratuit', 'is_public' => true, 'is_active' => true, 'trial_days' => 45])->assertOk()->assertJsonPath('data.trial_days', 45);
        $staff()->putJson('/api/v1/platform/plans/unknown', $terms)->assertNotFound();
    }

    public function test_invoices_of_every_client_and_platform_activity(): void
    {
        $staff = fn () => $this->withToken($this->platform);

        $staff()->postJson("/api/v1/platform/companies/{$this->company->ulid}/subscription",
            ['plan' => 'per_site', 'billing_interval' => 'monthly', 'sites_quantity' => 2, 'unit_amount' => '150'])->assertCreated();

        $staff()->getJson('/api/v1/platform/invoices')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.legal_name', 'Textile du Cap Bon')->assertJsonPath('data.0.total_amount', '358.000')->assertJsonPath('data.0.status', 'issued');
        $staff()->getJson('/api/v1/platform/invoices?filter[status]=paid')->assertOk()->assertJsonCount(0, 'data');
        $staff()->getJson('/api/v1/platform/invoices?filter[search]=Cap')->assertOk()->assertJsonCount(1, 'data');
        $staff()->getJson('/api/v1/platform/overview')->assertJsonPath('data.billing.invoices_open', 1)->assertJsonPath('data.subscriptions.active', 1);

        $activity = $staff()->getJson('/api/v1/platform/activity?filter[event]=subscription_activated')->assertOk()->assertJsonCount(1, 'data');
        $activity->assertJsonPath('data.0.company.legal_name', 'Textile du Cap Bon')->assertJsonPath('data.0.entity', 'company_subscriptions');
        // Operational data of a client never shows in the platform trail.
        $staff()->getJson('/api/v1/platform/activity?filter[entity]=waste_lots')->assertUnprocessable();
    }

    public function test_company_page_carries_what_is_needed_to_run_the_account(): void
    {
        $staff = fn () => $this->withToken($this->platform);
        $this->makeSite($this->company, 'NAB');
        $this->company->forceFill(['settings' => ['requested_plan' => 'per_site', 'requested_sites' => 2]])->save();

        // The overview's "plans to activate" opens exactly the clients that asked for a paid plan.
        $staff()->getJson('/api/v1/platform/companies?filter[plan_requested]=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.legal_name', 'Textile du Cap Bon');

        $staff()->postJson("/api/v1/platform/companies/{$this->company->ulid}/subscription",
            ['plan' => 'per_site', 'billing_interval' => 'monthly', 'sites_quantity' => 2, 'unit_amount' => '150'])->assertCreated();
        $staff()->getJson('/api/v1/platform/companies?filter[plan_requested]=1')->assertOk()->assertJsonCount(0, 'data');

        $page = $staff()->getJson("/api/v1/platform/companies/{$this->company->ulid}")->assertOk();
        $page->assertJsonPath('data.sites.0.code', 'NAB')->assertJsonCount(1, 'data.users')->assertJsonPath('data.users.0.email', $this->clientAdmin->email)
            ->assertJsonCount(1, 'data.invoices')->assertJsonPath('data.invoices.0.total_amount', '358.000')
            ->assertJsonPath('data.subscription.plan', 'per_site')->assertJsonPath('data.activity.0.event', 'subscription_activated')
            ->assertJsonPath('data.country_name.fr', 'Tunisie');
        // Nothing operational of the client is exposed to the platform.
        $this->assertArrayNotHasKey('lots', $page->json('data'));
    }
}
