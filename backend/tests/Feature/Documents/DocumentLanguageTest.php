<?php

namespace Tests\Feature\Documents;

use App\Modules\Billing\Actions\StartTrial;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Common\Support\DocumentLanguage;
use App\Modules\Identity\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Generated documents exist in French and in English: the language of the person who asks by default, another one
 * on request. Both languages carry exactly the same texts.
 */
class DocumentLanguageTest extends TestCase
{
    use InteractsWithTenants;

    public function test_both_languages_define_the_same_document_texts(): void
    {
        foreach (['documents', 'exports'] as $file) {
            $french = Arr::dot(require lang_path("fr/{$file}.php"));
            $english = Arr::dot(require lang_path("en/{$file}.php"));

            $this->assertSame([], array_keys(array_diff_key($french, $english)), "Missing in lang/en/{$file}.php");
            $this->assertSame([], array_keys(array_diff_key($english, $french)), "Missing in lang/fr/{$file}.php");

            // The same values are filled in (":from", ":count"…), whatever the word order.
            foreach ($french as $key => $text) {
                preg_match_all('/:[a-z_]+/', (string) $text, $expected);
                preg_match_all('/:[a-z_]+/', (string) $english[$key], $actual);
                sort($expected[0]);
                sort($actual[0]);
                $this->assertSame($expected[0], $actual[0], "Placeholders differ for {$file}.{$key}");
            }
        }
    }

    public function test_the_language_is_the_users_unless_another_one_is_asked(): void
    {
        app()->setLocale('fr');
        $this->assertSame('fr', DocumentLanguage::of(Request::create('/x')));
        // A standing preference of the device, then an explicit choice for one document.
        $this->assertSame('en', DocumentLanguage::of(Request::create('/x', 'GET', [], [], [], ['HTTP_X_DOCUMENT_LANGUAGE' => 'en'])));
        $this->assertSame('fr', DocumentLanguage::of(Request::create('/x?lang=fr', 'GET', [], [], [], ['HTTP_X_DOCUMENT_LANGUAGE' => 'en'])));
        // An unknown language is ignored.
        $this->assertSame('fr', DocumentLanguage::of(Request::create('/x?lang=de')));

        $this->assertSame('1 234,500', DocumentLanguage::number('1234.5'));
        $this->assertSame('37,5', DocumentLanguage::trimmed('37.500'));
        $inEnglish = DocumentLanguage::during('en', fn () => [DocumentLanguage::number('1234.5'), DocumentLanguage::trimmed('37.500'), DocumentLanguage::trimmed('120')]);
        $this->assertSame(['1,234.500', '37.5', '120'], $inEnglish);
        // The previous language is put back: a queue worker serves every user.
        $this->assertSame('fr', app()->getLocale());
    }

    public function test_an_invoice_reads_in_french_or_in_english(): void
    {
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        config(['billing.seller.tax_id' => '0000000X']);
        Queue::fake();
        $company = $this->makeCompany();
        $admin = $this->makeMember($company, ['client_admin' => null]);
        app(StartTrial::class)->handle($company);
        $staff = User::factory()->create();
        $staff->assignRole('super_admin');
        $this->withToken($staff->createToken('t')->plainTextToken)->postJson("/api/v1/platform/companies/{$company->ulid}/subscription",
            ['plan' => 'per_site', 'billing_interval' => 'monthly', 'sites_quantity' => 2, 'unit_amount' => '1150'])->assertCreated();

        $html = fn (string $language) => $this->tenant()->runAs($company, fn () => DocumentLanguage::during($language, function () {
            $invoice = Invoice::query()->firstOrFail();

            return view('pdf.invoice', ['invoice' => $invoice, 'items' => InvoiceItem::query()->where('invoice_id', $invoice->id)->get()])->render();
        }));

        $french = $html('fr');
        foreach (['Facture n°', 'Facturé à :', 'Total TTC', 'Droit de timbre', 'Abonnement DivaWaste par site — du', '2 300,000', 'virement bancaire'] as $text) {
            $this->assertStringContainsString($text, $french);
        }
        $english = $html('en');
        foreach (['Invoice no.', 'Billed to:', 'Total incl. tax', 'Stamp duty', 'DivaWaste per-site subscription — from', '2,300.000', 'bank transfer'] as $text) {
            $this->assertStringContainsString($text, $english);
        }
        $this->assertStringNotContainsString('Facture', $english);

        // The same choice through the API: the PDF is produced in either language.
        app('auth')->forgetGuards();
        $client = fn () => $this->withToken($this->tokenFor($admin, $company));
        $ulid = DB::table('invoices')->value('ulid');
        $this->assertStringStartsWith('%PDF-', (string) $client()->get("/api/v1/invoices/{$ulid}/pdf?lang=en")->assertOk()->getContent());
        $this->assertStringStartsWith('%PDF-', (string) $client()->get("/api/v1/invoices/{$ulid}/pdf", ['X-Document-Language' => 'fr'])->assertOk()->getContent());
    }

    public function test_a_report_keeps_the_language_it_was_requested_in(): void
    {
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        Queue::fake();
        $company = $this->makeCompany();
        $this->makeSite($company, 'NAB');
        $admin = $this->makeMember($company, ['client_admin' => null]);
        app(StartTrial::class)->handle($company);
        $client = fn () => $this->withToken($this->tokenFor($admin, $company));
        $period = ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()];

        // Screen in French, document asked in English.
        $client()->postJson('/api/v1/reports', ['report_type' => 'rse_summary', 'format' => 'pdf', 'lang' => 'en'] + $period)->assertStatus(202)->assertJsonPath('data.parameters.lang', 'en');
        // Nothing asked: the language of the screen.
        $client()->postJson('/api/v1/reports', ['report_type' => 'rse_summary', 'format' => 'xlsx'] + $period)->assertStatus(202)->assertJsonPath('data.parameters.lang', 'fr');
        $client()->postJson('/api/v1/reports', ['report_type' => 'rse_summary', 'format' => 'xlsx'] + $period, ['Accept-Language' => 'en'])->assertStatus(202)->assertJsonPath('data.parameters.lang', 'en');
        $client()->postJson('/api/v1/reports', ['report_type' => 'rse_summary', 'format' => 'pdf', 'lang' => 'de'] + $period)->assertUnprocessable();

        $this->assertSame(['en', 'fr', 'en'], DB::table('generated_reports')->orderBy('id')->get()->map(fn ($row) => json_decode((string) $row->parameters, true)['lang'])->all());
    }
}
