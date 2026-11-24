<?php

namespace Tests\Feature\Production;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * A materials file of an export plant: one supplier written two ways, material batches costed in dollars.
 */
class ImportSuppliersAndDollarsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
        $this->makeSite($this->company, 'DJO');
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
    }

    /** @return array<string, mixed> */
    private function import(string $target, string $csv, array $mapping): array
    {
        return $this->withToken($this->tokenFor($this->admin, $this->company))->post('/api/v1/production/imports/run',
            ['target' => $target, 'file' => UploadedFile::fake()->createWithContent("{$target}.csv", $csv), 'mapping' => $mapping], ['Accept' => 'application/json'])
            ->assertOk()->json('data');
    }

    public function test_a_supplier_written_two_ways_is_one_supplier(): void
    {
        $done = $this->import('materials', "Référence,Désignation,Fournisseur,Unité\n13-0641-1,IFU TRUPULL,PRINTING+,piece\n13-9202-1,IFU ULTRASLING,PRINTING +,piece\n",
            ['reference' => 0, 'name' => 1, 'supplier' => 2, 'unit' => 3]);

        $this->assertSame([2, [], ['suppliers' => 1]], [$done['created'], $done['errors'], $done['also_created']]);
        $this->assertSame(1, DB::table('production_suppliers')->where('company_id', $this->company->id)->count());
    }

    public function test_batches_are_costed_in_us_dollars(): void
    {
        $this->import('materials', "Référence,Désignation,Unité\n10099,BOA STEEL LACE,m\n", ['reference' => 0, 'name' => 1, 'unit' => 2]);

        $done = $this->import('received_lots', "Matière,Lot,Date,Quantité,PU,Devise\n10099,COUT-STD,01/01/2026,1,0.69,usd\n",
            ['material' => 0, 'batch_number' => 1, 'received_on' => 2, 'batch_quantity' => 3, 'unit_price' => 4, 'currency' => 5]);

        $this->assertSame([1, []], [$done['created'], $done['errors']]);
        $this->assertSame('USD', DB::table('raw_material_batches')->where('batch_number', 'COUT-STD')->value('currency_code'));
    }

    public function test_materials_counted_by_area_or_in_litres_keep_their_unit(): void
    {
        $done = $this->import('materials', "Référence,Désignation,Unité\n10000-002,BX TERPOLYMER,Square Feet\n14-0595,SPUNLACE,Square Meter\n10060-001,FOAM OLETEX,Square Inch\n50020,SOLUTION,Liter\n",
            ['reference' => 0, 'name' => 1, 'unit' => 2]);

        $this->assertSame([4, []], [$done['created'], $done['errors']]);
        $this->assertSame(['10000-002' => 'ft2', '10060-001' => 'in2', '14-0595' => 'm2', '50020' => 'l'], DB::table('raw_materials as m')->join('units as u', 'u.id', '=', 'm.unit_id')
            ->where('m.company_id', $this->company->id)->orderBy('m.reference')->pluck('u.code', 'm.reference')->all());
    }

    public function test_a_batch_price_keeps_six_decimals(): void
    {
        $this->import('materials', "Référence,Désignation,Unité\n10002 YDS,UBL STRETCH,m\n", ['reference' => 0, 'name' => 1, 'unit' => 2]);

        $this->import('received_lots', "Matière,Lot,Date,Quantité,PU,Devise\n10002 YDS,COUT-STD,01/01/2026,1,10.869215,USD\n",
            ['material' => 0, 'batch_number' => 1, 'received_on' => 2, 'batch_quantity' => 3, 'unit_price' => 4, 'currency' => 5]);

        $this->assertSame('10.869215', (string) DB::table('raw_material_batches')->where('batch_number', 'COUT-STD')->value('unit_price'));
    }

    public function test_a_batch_priced_to_four_decimals_takes_the_six_of_a_new_file(): void
    {
        $this->import('materials', "Référence,Désignation,Unité\n13-2562,LABEL,piece\n", ['reference' => 0, 'name' => 1, 'unit' => 2]);
        $mapping = ['material' => 0, 'batch_number' => 1, 'received_on' => 2, 'batch_quantity' => 3, 'unit_price' => 4, 'currency' => 5];
        $this->import('received_lots', "Matière,Lot,Date,Quantité,PU,Devise\n13-2562,COUT-STD,01/01/2026,1,0.0175,USD\n", $mapping);

        $done = $this->import('received_lots', "Matière,Lot,Date,Quantité,PU,Devise\n13-2562,COUT-STD,01/01/2026,1,0.01751,USD\n", $mapping);

        $this->assertSame([1, '0.017510'], [$done['updated'], (string) DB::table('raw_material_batches')->where('batch_number', 'COUT-STD')->value('unit_price')]);
    }
}
