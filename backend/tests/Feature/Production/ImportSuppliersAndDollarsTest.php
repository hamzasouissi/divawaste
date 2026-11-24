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
}
