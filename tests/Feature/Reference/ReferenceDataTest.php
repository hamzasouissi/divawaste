<?php

namespace Tests\Feature\Reference;

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_loads_cahier_vocabularies(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame(3, DB::table('currencies')->count());
        $this->assertSame(4, DB::table('countries')->count());
        $this->assertSame(14, DB::table('waste_catalog_items')->count(), 'M1-03 default types');
        $this->assertSame(1, DB::table('zone_types')->where('code', 'waste_storage')->where('is_waste_storage', true)->count());
        $this->assertSame(3, DB::table('currencies')->where('code', 'TND')->value('minor_unit'));
        $this->assertSame(['TN'], DB::table('countries')->where('is_signup_enabled', true)->pluck('iso2')->all());
        $this->assertSame(3, DB::table('treatment_channels')->where('is_valorization', true)->count());
    }

    public function test_translated_names_round_trip_utf8(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $name = json_decode((string) DB::table('waste_catalog_items')->where('code', 'selvedges')->value('name'), true);

        $this->assertSame('Lisières', $name['fr']);
    }

    public function test_foreign_keys_are_enforced(): void
    {
        $this->expectException(QueryException::class);

        DB::table('countries')->insert([
            'iso2' => 'XX', 'iso3' => 'XXX', 'name' => '{}', 'currency_code' => 'ZZZ', 'default_timezone' => 'UTC',
            'phone_prefix' => '+0', 'tax_id_label' => '{}', 'waste_code_system' => 'TN',
        ]);
    }

    public function test_tax_rate_requires_a_rate_or_a_fixed_amount(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->expectException(QueryException::class);

        DB::table('tax_rates')->insert([
            'country_id' => DB::table('countries')->where('iso2', 'TN')->value('id'),
            'tax_type' => 'vat', 'code' => 'TN_VAT', 'name' => '{}', 'valid_from' => '2026-01-01',
        ]);
    }

    public function test_regulatory_codes_are_unique_per_code_system(): void
    {
        $row = ['code_system' => 'EU_LOW', 'code' => '04 02 22', 'level' => 3, 'label' => '{}'];
        DB::table('regulatory_waste_codes')->insert($row);
        DB::table('regulatory_waste_codes')->insert(['code_system' => 'TN'] + $row);

        $this->expectException(QueryException::class);
        DB::table('regulatory_waste_codes')->insert($row);
    }
}
