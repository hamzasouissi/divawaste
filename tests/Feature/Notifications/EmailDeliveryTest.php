<?php

namespace Tests\Feature\Notifications;

use App\Modules\Billing\Actions\StartTrial;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Support\EmailLog;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * M0-06 / M7-01: the account emails really go through the mail transport (nothing is faked here), reach the
 * company's contact, and each one leaves a trace saying through which transport it went and whether it was accepted.
 */
class EmailDeliveryTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private User $contact;

    private string $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        // Its own folder: the emails kept by a local or demo instance of the same checkout are not touched.
        config(['billing.seller.tax_id' => '0000000X', 'mail.outbox.keep_bodies' => true, 'mail.outbox.path' => storage_path('framework/testing/mail-outbox')]);

        $this->company = $this->makeCompany();
        $this->contact = $this->makeMember($this->company, ['client_admin' => null]);
        $this->company->forceFill(['legal_name' => 'Maille Bizerte SARL', 'tax_id' => '4455667H', 'status' => 'pending_approval',
            'primary_contact_user_id' => $this->contact->id, 'submitted_at' => now()])->save();

        $staff = User::factory()->create();
        $staff->assignRole('super_admin');
        $this->platform = $staff->createToken('t')->plainTextToken;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(EmailLog::directory());
        parent::tearDown();
    }

    /**
     * The emails the mail transport received, in order (the "array" transport of the test environment keeps them).
     *
     * @return Collection<int, Email>
     */
    private function delivered(): Collection
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages()->map(function (SentMessage $sent): Email {
            $email = $sent->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);

            return $email;
        })->values();
    }

    public function test_approval_then_plan_activation_email_the_company_contact(): void
    {
        $staff = fn () => $this->withToken($this->platform);

        $staff()->postJson("/api/v1/platform/companies/{$this->company->ulid}/approve")->assertOk();
        $staff()->postJson("/api/v1/platform/companies/{$this->company->ulid}/subscription",
            ['plan' => 'per_site', 'billing_interval' => 'monthly', 'sites_quantity' => 1, 'unit_amount' => '150'])->assertCreated();

        // What the transport received: two emails, both to the contact who registered the company.
        $messages = $this->delivered();
        $this->assertSame([$this->contact->email, $this->contact->email], $messages->map(fn (Email $email) => $email->getTo()[0]->getAddress())->all());
        $this->assertSame(['Votre compte DivaWaste est validé', 'Votre abonnement DivaWaste est activé'], $messages->map(fn (Email $email) => $email->getSubject())->all());

        $activation = (string) $messages[1]->getHtmlBody();
        $invoice = DB::table('invoices')->where('company_id', $this->company->id)->first(['invoice_number', 'total_amount']);
        $this->assertSame('179.500', $invoice->total_amount);
        foreach (['Maille Bizerte SARL', 'Abonnement par site', $invoice->invoice_number, '179.500 TND', 'virement bancaire', '/subscription', 'Cordialement'] as $expected) {
            $this->assertStringContainsString($expected, $activation);
        }

        // The trace: transport "array" in tests, so "sent" here means handed to the transport, not delivered.
        $logs = DB::table('notification_logs')->orderBy('id')->get();
        $this->assertSame(['company_approved', 'subscription.activated'], $logs->pluck('notification_type')->all());
        $this->assertSame(['sent', 'sent'], $logs->pluck('status')->all());
        $this->assertSame(['array', 'array'], $logs->pluck('transport')->all());
        $this->assertSame($this->contact->email, $logs[1]->recipient);
        $this->assertSame($this->company->id, (int) $logs[1]->company_id);
        $this->assertStringContainsString($invoice->invoice_number, (string) file_get_contents(EmailLog::bodyPath((int) $logs[1]->id)));

        // The same information is in the application, for the users who do not read the email.
        $this->withToken($this->tokenFor($this->contact, $this->company->refresh()))->getJson('/api/v1/me/notifications')->assertOk()
            ->assertJsonPath('data.0.type', 'subscription.activated');

        $this->artisan('mail:outbox')->expectsOutputToContain('NOT delivered')->expectsOutputToContain('Votre abonnement DivaWaste est activé')->assertSuccessful();
    }

    public function test_rejection_and_suspension_are_emailed_with_their_reason(): void
    {
        $staff = fn () => $this->withToken($this->platform);
        $staff()->postJson("/api/v1/platform/companies/{$this->company->ulid}/reject", ['reason' => 'Matricule fiscal illisible'])->assertOk();

        $other = $this->makeCompany();
        $owner = $this->makeMember($other, ['client_admin' => null]);
        $other->forceFill(['primary_contact_user_id' => $owner->id])->save();
        app(StartTrial::class)->handle($other);
        $staff()->postJson("/api/v1/platform/companies/{$other->ulid}/suspend", ['reason' => 'Facture impayée'])->assertOk();
        $staff()->postJson("/api/v1/platform/companies/{$other->ulid}/reactivate")->assertOk();

        $messages = $this->delivered();
        $this->assertSame(['Votre inscription DivaWaste n\'a pas été retenue', 'Votre compte DivaWaste est suspendu', 'Votre compte DivaWaste est réactivé'],
            $messages->map(fn (Email $email) => $email->getSubject())->all());
        $this->assertStringContainsString('Matricule fiscal illisible', (string) $messages[0]->getHtmlBody());
        $this->assertSame($owner->email, $messages[1]->getTo()[0]->getAddress());
        $this->assertStringContainsString('Facture impayée', (string) $messages[1]->getHtmlBody());
        $this->assertSame(['company_rejected', 'company_suspended', 'company_reactivated'], DB::table('notification_logs')->orderBy('id')->pluck('notification_type')->all());
    }

    public function test_a_refused_email_is_traced_and_does_not_undo_the_decision(): void
    {
        // A mail server nobody listens on: the connection is refused.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 9, 'mail.mailers.smtp.timeout' => 2]);
        Mail::forgetMailers();

        $this->withToken($this->platform)->postJson("/api/v1/platform/companies/{$this->company->ulid}/approve")->assertOk();

        $this->assertSame('active', $this->company->refresh()->status->value);
        $log = DB::table('notification_logs')->first();
        $this->assertSame(['failed', 'smtp', 'company_approved', $this->contact->email, 'Votre compte DivaWaste est validé'],
            [$log->status, $log->transport, $log->notification_type, $log->recipient, $log->subject]);
        $this->assertNotEmpty($log->error_message);
        $this->artisan('mail:outbox')->expectsOutputToContain('FAILED')->assertSuccessful();
        $this->artisan('mail:test', ['email' => 'someone@example.test'])->expectsOutputToContain('refused the message')->assertFailed();
    }
}
