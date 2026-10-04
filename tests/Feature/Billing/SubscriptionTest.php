<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Actions\ManageSubscription;
use App\Modules\Billing\Actions\StartTrial;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\BusinessEventNotification;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * M0-03, M0-04, M0-11, M7-04: trial, plan activation by the platform, automatic invoicing, bank transfer, cancellation.
 */
class SubscriptionTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private User $admin;

    private string $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        Notification::fake();
        config(['billing.seller.tax_id' => '0000000X']);

        $this->company = $this->makeCompany();
        $this->company->forceFill(['tax_id' => '1234567A', 'legal_name' => 'Textile du Cap Bon'])->save();
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
        app(StartTrial::class)->handle($this->company);

        $staff = User::factory()->create();
        $staff->assignRole('super_admin');
        $this->platform = $staff->createToken('t')->plainTextToken;
    }

    public function test_trial_then_plan_request_activation_invoice_transfer_and_renewal(): void
    {
        $client = fn () => $this->withToken($this->tokenFor($this->admin, $this->company));

        $client()->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.current.plan', 'trial')->assertJsonPath('data.current.status', 'trialing');
        $client()->postJson('/api/v1/subscription/change-request', ['plan' => 'trial', 'sites' => 2])->assertUnprocessable();
        $client()->postJson('/api/v1/subscription/change-request', ['plan' => 'per_site', 'sites' => 2])->assertNoContent();
        $client()->getJson('/api/v1/subscription')->assertJsonPath('data.requested_plan', 'per_site')->assertJsonPath('data.requested_sites', 2);

        // Only the platform activates a plan and sets its price.
        $terms = ['plan' => 'per_site', 'billing_interval' => 'monthly', 'sites_quantity' => 2, 'unit_amount' => '150'];
        $client()->postJson("/api/v1/platform/companies/{$this->company->ulid}/subscription", $terms)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->platform)->postJson("/api/v1/platform/companies/{$this->company->ulid}/subscription", $terms)->assertCreated();

        $client()->getJson('/api/v1/subscription')->assertJsonPath('data.current.plan', 'per_site')->assertJsonPath('data.current.status', 'active')
            ->assertJsonPath('data.requested_plan', null);
        $this->assertSame(1, DB::table('company_subscriptions')->where('company_id', $this->company->id)->where('is_current', true)->count());

        // M7-04: 2 sites × 150 = 300 HT, VAT 19 % = 57, stamp duty 1 → 358 TND; tax ID on the invoice.
        $invoice = $client()->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['300.000', '57.000', '1.000', '358.000'], [$invoice['subtotal_amount'], $invoice['tax_amount'], $invoice['stamp_duty_amount'], $invoice['total_amount']]);
        $this->assertSame('issued', $invoice['status']);
        $this->assertSame('1234567A', json_decode((string) DB::table('invoices')->value('buyer_snapshot'), true)['tax_id']);
        Notification::assertSentTo($this->admin, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'invoice.issued');
        $this->assertStringStartsWith('%PDF-', $client()->get("/api/v1/invoices/{$invoice['ulid']}/pdf")->assertOk()->getContent());

        // M0-04: the client declares its transfer, the platform validates it.
        $client()->postJson("/api/v1/invoices/{$invoice['ulid']}/payments", ['amount' => '358', 'bank_reference' => 'VIR-2026-0042', 'declared_paid_on' => now()->toDateString()])->assertCreated();
        $client()->getJson('/api/v1/invoices')->assertJsonPath('data.0.pending_payment', true);
        app('auth')->forgetGuards();
        $payment = $this->withToken($this->platform)->getJson('/api/v1/platform/payments')->assertOk()->assertJsonCount(1, 'data')->json('data.0.ulid');
        $this->withToken($this->platform)->postJson("/api/v1/platform/payments/{$payment}/review", ['decision' => 'rejected'])->assertUnprocessable();
        $this->withToken($this->platform)->postJson("/api/v1/platform/payments/{$payment}/review", ['decision' => 'validated'])->assertNoContent();
        $client()->getJson('/api/v1/invoices')->assertJsonPath('data.0.status', 'paid')->assertJsonPath('data.0.amount_paid', '358.000');
        Notification::assertSentTo($this->admin, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'payment.reviewed');

        // Automatic renewal: nothing while the period runs, one invoice once it has ended, never two.
        $task = app(ManageSubscription::class);
        $this->assertSame(0, $task->runDaily()['renewed']);
        $this->travel(1)->months();
        $this->travel(1)->days();
        $this->assertSame(1, $task->runDaily()['renewed']);
        $this->assertSame(0, $task->runDaily()['renewed']);
        $client()->getJson('/api/v1/invoices')->assertJsonCount(2, 'data');
        $periods = DB::table('invoices')->orderBy('id')->get(['period_start', 'period_end']);
        $this->assertSame(now()->parse($periods[0]->period_end)->addDay()->toDateString(), $periods[1]->period_start);

        // M0-11: cancellation takes effect at the end of the paid period, then the account becomes read-only.
        $client()->postJson('/api/v1/subscription/cancel', ['reason' => 'Fin de contrat'])->assertNoContent();
        $client()->getJson('/api/v1/subscription')->assertJsonPath('data.current.cancel_at_period_end', true)->assertJsonPath('data.current.status', 'active');
        $this->travel(1)->months();
        $this->travel(1)->days();
        $this->assertSame(1, $task->runDaily()['cancelled']);
        $client()->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(2, 'data');
        $client()->postJson('/api/v1/sites', ['code' => 'NEW', 'name' => 'Nouveau site', 'country' => 'TN'])->assertStatus(402);
        $client()->getJson('/api/v1/sites')->assertOk();
    }

    public function test_trial_warns_before_it_ends_then_turns_the_account_read_only(): void
    {
        $task = app(ManageSubscription::class);
        $client = fn () => $this->withToken($this->tokenFor($this->admin, $this->company));

        $this->travel(23)->days();
        $this->assertSame(1, $task->runDaily()['trial_warnings']);
        Notification::assertSentTo($this->admin, BusinessEventNotification::class, fn (BusinessEventNotification $n) => $n->type === 'subscription.trial_ending' && $n->params['days'] === 7);
        $client()->postJson('/api/v1/sites', ['code' => 'A1', 'name' => 'Site A', 'country' => 'TN'])->assertCreated();

        $this->travel(8)->days();
        $this->assertSame(1, $task->runDaily()['trials_expired']);
        $client()->postJson('/api/v1/sites', ['code' => 'B1', 'name' => 'Site B', 'country' => 'TN'])->assertStatus(402)->assertJsonPath('code', 'PAYMENT_REQUIRED');
        // Data stays readable and the client can still ask for a plan.
        $client()->getJson('/api/v1/sites')->assertOk()->assertJsonCount(1, 'data');
        $client()->postJson('/api/v1/subscription/change-request', ['plan' => 'per_volume', 'sites' => 1])->assertNoContent();
    }

    public function test_billing_of_one_company_is_invisible_to_another(): void
    {
        app('auth')->forgetGuards();
        $this->withToken($this->platform)->postJson("/api/v1/platform/companies/{$this->company->ulid}/subscription",
            ['plan' => 'per_volume', 'billing_interval' => 'yearly', 'sites_quantity' => 1, 'unit_amount' => '1200'])->assertCreated();
        $invoice = DB::table('invoices')->value('ulid');

        $other = $this->makeCompany();
        $stranger = $this->tokenFor($this->makeMember($other, ['client_admin' => null]), $other);
        $this->withToken($stranger)->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($stranger)->get("/api/v1/invoices/{$invoice}/pdf")->assertNotFound();
        $this->withToken($stranger)->postJson("/api/v1/invoices/{$invoice}/payments", ['amount' => '1', 'bank_reference' => 'x', 'declared_paid_on' => now()->toDateString()])->assertNotFound();

        // Someone who is not allowed to see invoices in the right company does not see them either.
        $operator = $this->tokenFor($this->makeMember($this->company, ['environment_manager' => null]), $this->company);
        $this->withToken($operator)->getJson('/api/v1/invoices')->assertForbidden();
    }
}
