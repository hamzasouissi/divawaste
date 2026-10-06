<?php

namespace Database\Factories;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Requires ReferenceDataSeeder (countries/currencies).
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'company_type' => 'industrial',
            'legal_name' => fake()->company(),
            'tax_id' => fake()->unique()->numerify('#######A'),
            'country_id' => DB::table('countries')->where('iso2', 'TN')->value('id'),
            'currency_code' => 'TND',
            'timezone' => 'Africa/Tunis',
        ];
    }

    public function active(): static
    {
        return $this->afterMaking(fn (Company $company) => $company->forceFill(['status' => 'active']));
    }

    public function provider(): static
    {
        return $this->state(fn () => ['company_type' => 'provider']);
    }
}
