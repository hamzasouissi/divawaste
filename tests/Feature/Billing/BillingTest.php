<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Actions\IssueInvoice;
use App\Modules\Billing\Actions\ReviewPayment;
use App\Modules\Billing\Actions\StartTrial;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\Payment;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
    }

    public function test_trial_lasts_thirty_days_and_is_the_single_current_subscription(): void
    {
        $subscription = app(StartTrial::class)->handle($this->company);

        $this->assertSame('trialing', $subscription->status->value);
        $this->assertSame(30, (int) $subscription->starts_at->diffInDays($subscription->trial_ends_at));
        $this->assertSame('TND', $subscription->currency_code);

        $this->expectException(BusinessRuleViolation::class);
        app(StartTrial::class)->handle($this->company);
    }

    public function test_database_allows_only_one_current_subscription(): void
    {
        app(StartTrial::class)->handle($this->company);

        $this->expectException(QueryException::class);
        DB::table('company_subscriptions')->insert([
            'ulid' => '01JC2Z8M4V7H3K9P2RQW5X6Y8Z', 'company_id' => $this->company->id, 'status' => 'active', 'currency_code' => 'TND',
            'subscription_plan_id' => DB::table('subscription_plans')->value('id'), 'starts_at' => now(), 'is_current' => true, 'price_snapshot' => '{}',
        ]);
    }

    public function test_issuing_computes_exact_totals_and_snapshots(): void
    {
        $invoice = $this->draftInvoice([['2', '49.995', '19']]);

        $issued = $this->tenant()->runAs($this->company, fn () => app(IssueInvoice::class)->handle($invoice));

        // 2 × 49.995 = 99.990; 19 % = 18.9981 → 18.998 (TND, 3 decimals, half-up).
        $this->assertSame('99.990', $issued->subtotal_amount);
        $this->assertSame('18.998', $issued->tax_amount);
        $this->assertSame('118.988', $issued->total_amount);
        $this->assertSame($this->company->legal_name, $issued->buyer_snapshot['legal_name']);
        $this->assertSame('TN', $issued->buyer_snapshot['country']);
    }

    public function test_invoice_numbers_are_sequential_per_series_and_year(): void
    {
        $year = now()->year;
        $numbers = $this->tenant()->runAs($this->company, fn () => [
            app(IssueInvoice::class)->handle($this->draftInvoice())->invoice_number,
            app(IssueInvoice::class)->handle($this->draftInvoice())->invoice_number,
        ]);

        $this->assertSame(["DW-{$year}-000001", "DW-{$year}-000002"], $numbers);
    }

    public function test_issued_invoice_and_its_lines_are_immutable(): void
    {
        $invoice = $this->tenant()->runAs($this->company, fn () => app(IssueInvoice::class)->handle($this->draftInvoice()));

        $this->tenant()->runAs($this->company, function () use ($invoice) {
            try {
                $invoice->forceFill(['total_amount' => '1.000'])->save();
                $this->fail('Issued invoice was modified');
            } catch (BusinessRuleViolation) {
            }

            $this->expectException(BusinessRuleViolation::class);
            InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'item_type' => 'adjustment', 'description' => 'x', 'quantity' => '1', 'unit_amount' => '1']);
        });
    }

    public function test_empty_invoice_cannot_be_issued(): void
    {
        $invoice = $this->draftInvoice([]);

        $this->expectException(BusinessRuleViolation::class);
        $this->tenant()->runAs($this->company, fn () => app(IssueInvoice::class)->handle($invoice));
    }

    public function test_bank_transfer_flow_declare_then_validate(): void
    {
        $invoice = $this->tenant()->runAs($this->company, fn () => app(IssueInvoice::class)->handle($this->draftInvoice([['1', '100', '0']])));
        $admin = $this->makeMember($this->company, ['client_admin' => null]);
        $token = $this->tokenFor($admin, $this->company);

        $ulid = $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->ulid}/payments", ['amount' => '40.000', 'bank_reference' => 'VIR-001', 'declared_paid_on' => now()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.ulid');

        $staff = User::factory()->create();
        $staff->assignRole('super_admin');
        $review = app(ReviewPayment::class);

        $this->tenant()->runAs($this->company, function () use ($review, $staff, $ulid, $invoice) {
            $review->validate(Payment::query()->where('ulid', $ulid)->firstOrFail(), $staff);
            $this->assertSame('partially_paid', $invoice->fresh()?->status->value);

            $second = Payment::query()->create(['invoice_id' => $invoice->id, 'method' => 'bank_transfer', 'amount' => '60.000', 'currency_code' => 'TND']);
            $review->validate($second, $staff);
            $this->assertSame('paid', $invoice->fresh()?->status->value);
            $this->assertSame('100.000', $invoice->fresh()?->amount_paid);
        });
    }

    public function test_payment_review_requires_platform_permission_and_happens_once(): void
    {
        $invoice = $this->tenant()->runAs($this->company, fn () => app(IssueInvoice::class)->handle($this->draftInvoice()));
        $payment = $this->tenant()->runAs($this->company, fn () => Payment::query()->create(['invoice_id' => $invoice->id, 'method' => 'bank_transfer', 'amount' => '1', 'currency_code' => 'TND']));
        $staff = User::factory()->create();
        $staff->assignRole('super_admin');

        $this->tenant()->runAs($this->company, function () use ($payment, $staff) {
            app(ReviewPayment::class)->reject($payment, $staff, 'Unknown transfer');

            try {
                app(ReviewPayment::class)->validate($payment, User::factory()->create());
                $this->fail('Non-staff validated a payment');
            } catch (AuthorizationException) {
            }

            $this->expectException(BusinessRuleViolation::class);
            app(ReviewPayment::class)->validate($payment, $staff);
        });
    }

    public function test_payments_cannot_be_declared_on_a_draft_or_another_companys_invoice(): void
    {
        $draft = $this->draftInvoice();
        $admin = $this->makeMember($this->company, ['client_admin' => null]);
        $payload = ['amount' => '1', 'bank_reference' => 'X', 'declared_paid_on' => now()->toDateString()];

        $this->withToken($this->tokenFor($admin, $this->company))
            ->postJson("/api/v1/invoices/{$draft->ulid}/payments", $payload)
            ->assertStatus(409)->assertJsonPath('code', 'INVOICE_NOT_PAYABLE');

        $other = $this->makeCompany();
        $foreign = $this->tenant()->runAs($other, fn () => Invoice::query()->create(['currency_code' => 'TND']));

        $this->withToken($this->tokenFor($admin, $this->company))
            ->postJson("/api/v1/invoices/{$foreign->ulid}/payments", $payload)
            ->assertNotFound();
    }

    /**
     * @param  list<array{string, string, string}>  $lines  [quantity, unit amount, tax %]
     */
    private function draftInvoice(array $lines = [['1', '10', '0']]): Invoice
    {
        return $this->tenant()->runAs($this->company, function () use ($lines) {
            $invoice = Invoice::query()->create(['currency_code' => 'TND']);
            foreach ($lines as [$quantity, $unit, $rate]) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id, 'item_type' => 'subscription', 'description' => 'Abonnement',
                    'quantity' => $quantity, 'unit_amount' => $unit, 'tax_rate_pct' => $rate,
                ]);
            }

            return $invoice->refresh();
        });
    }
}
