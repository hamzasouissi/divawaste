<?php

namespace Tests\Feature\Catalog;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Colours work like waste types: defaults for everyone, plus the ones each company adds for itself.
 */
class ColorsTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        $this->company = $this->makeCompany();
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
    }

    public function test_a_company_adds_renames_and_withdraws_its_own_colours(): void
    {
        $client = fn () => $this->withToken($this->tokenFor($this->admin, $this->company));
        $defaults = DB::table('color_families')->whereNull('company_id')->count();
        $this->assertGreaterThan(0, $defaults);

        $client()->getJson('/api/v1/colors')->assertOk()->assertJsonCount($defaults, 'data')->assertJsonPath('data.0.is_custom', false);

        $created = $client()->postJson('/api/v1/colors', ['name' => 'Bleu canard', 'hex_color' => '#0a6c74'])->assertCreated()
            ->assertJsonPath('data.name.fr', 'Bleu canard')->assertJsonPath('data.hex_color', '#0A6C74')->assertJsonPath('data.is_custom', true)->json('data');
        $this->assertStringStartsWith('c_', $created['code']);

        // Offered at once in the forms, after the defaults.
        $reference = $client()->getJson('/api/v1/reference')->assertOk()->json('data.color_families');
        $this->assertCount($defaults + 1, $reference);
        $this->assertSame($created['code'], end($reference)['code']);

        // The same name twice, a malformed colour and a missing name are refused.
        $client()->postJson('/api/v1/colors', ['name' => 'bleu CANARD'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $client()->postJson('/api/v1/colors', ['name' => 'Vert', 'hex_color' => 'green'])->assertUnprocessable()->assertJsonValidationErrors(['hex_color']);
        $client()->postJson('/api/v1/colors', [])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $client()->putJson("/api/v1/colors/{$created['code']}", ['name' => 'Bleu pétrole', 'hex_color' => null])->assertOk()
            ->assertJsonPath('data.name.fr', 'Bleu pétrole')->assertJsonPath('data.hex_color', null);

        // A default colour cannot be changed.
        $default = DB::table('color_families')->whereNull('company_id')->value('code');
        $client()->putJson("/api/v1/colors/{$default}", ['name' => 'Autre'])->assertNotFound();

        // Withdrawn: no longer offered, never deleted.
        $client()->putJson("/api/v1/colors/{$created['code']}", ['name' => 'Bleu pétrole', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertCount($defaults, $client()->getJson('/api/v1/reference')->json('data.color_families'));
        $this->assertDatabaseHas('color_families', ['code' => $created['code'], 'is_active' => false]);
    }

    public function test_a_colour_belongs_to_its_company_only(): void
    {
        $mine = $this->withToken($this->tokenFor($this->admin, $this->company))->postJson('/api/v1/colors', ['name' => 'Bleu canard'])->assertCreated()->json('data.code');

        $other = $this->makeCompany();
        $otherAdmin = $this->makeMember($other, ['client_admin' => null]);
        app('auth')->forgetGuards();
        $stranger = fn () => $this->withToken($this->tokenFor($otherAdmin, $other));

        $this->assertNotContains($mine, array_column($stranger()->getJson('/api/v1/colors')->assertOk()->json('data'), 'code'));
        $this->assertNotContains($mine, array_column($stranger()->getJson('/api/v1/reference')->json('data.color_families'), 'code'));
        $stranger()->putJson("/api/v1/colors/{$mine}", ['name' => 'Volé'])->assertNotFound();
        // The other company may use the same name for its own colour, but cannot put mine on its waste types.
        $stranger()->postJson('/api/v1/colors', ['name' => 'Bleu canard'])->assertCreated();
        $stranger()->postJson('/api/v1/waste-types', ['code' => 'TEST', 'name' => 'Test', 'waste_family' => DB::table('waste_families')->value('code'), 'color_family' => $mine])
            ->assertUnprocessable()->assertJsonValidationErrors(['color_family']);
    }

    public function test_a_user_without_the_right_cannot_add_a_colour_and_messages_follow_the_screen_language(): void
    {
        $operator = $this->makeMember($this->company, ['workshop_operator' => $this->makeSite($this->company, 'NAB')]);
        $this->withToken($this->tokenFor($operator, $this->company))->postJson('/api/v1/colors', ['name' => 'Rose'])->assertForbidden();

        app('auth')->forgetGuards();
        $client = fn () => $this->withToken($this->tokenFor($this->admin, $this->company));
        $this->assertSame('Ce champ est obligatoire.', $client()->postJson('/api/v1/colors', [])->json('errors.name.0'));
        $this->assertSame('The name field is required.', $client()->postJson('/api/v1/colors', [], ['Accept-Language' => 'en'])->json('errors.name.0'));
    }
}
