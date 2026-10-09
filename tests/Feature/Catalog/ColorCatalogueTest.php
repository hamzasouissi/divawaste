<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Support\ColorCatalogueImporter;
use App\Modules\Catalog\Support\Colors;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProvisionalReferenceSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The reference catalogue of colours: imported from files, searched by everyone, modified by nobody, and the
 * company colours taken from it. The references used here are made up for the tests.
 */
class ColorCatalogueTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private User $admin;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
        $this->company = $this->makeCompany();
        $this->admin = $this->makeMember($this->company, ['client_admin' => null]);
        $this->folder = storage_path('framework/testing/colors-'.uniqid());
        File::ensureDirectoryExists($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    private function file(string $name, mixed $content): string
    {
        $path = $this->folder.'/'.$name;
        file_put_contents($path, is_string($content) ? $content : json_encode($content));

        return $path;
    }

    private function catalogue(): string
    {
        $this->file('pantone-coated.json', [
            ['pantone' => 't-100-c', 'hex' => '#f6eb61'], ['pantone' => 't-200-c', 'hex' => '#ba0c2f'], ['pantone' => 't-300-c', 'hex' => '#005eb8'],
            ['pantone' => 't-warm-gray-1-c', 'hex' => '#d7d2cb'], ['pantone' => 't-871-c', 'hex' => '#84754e'],
        ]);
        $this->file('pantone-metallic.json', [['pantone' => 't-871-c', 'hex' => '#84764d'], ['pantone' => 't-8001-c', 'hex' => '#8d8c8a']]);
        $this->artisan('colors:import', ['path' => $this->folder])->assertExitCode(0);

        return $this->folder;
    }

    private function catalogueSize(): int
    {
        return Colors::catalogue()->count();
    }

    private function client(?User $user = null, ?Company $company = null): static
    {
        return $this->withToken($this->tokenFor($user ?? $this->admin, $company ?? $this->company));
    }

    public function test_existing_colours_keep_their_identity_and_gain_the_derived_values(): void
    {
        // Seeded defaults: same codes and tints as before, now marked as the platform's and carrying RGB and Lab.
        $white = DB::table('color_families')->where('code', 'white')->first();
        $this->assertSame(['#FFFFFF', 'platform', 'custom', 255, 255, 255], [$white->hex_color, $white->source, $white->color_system, $white->rgb_r, $white->rgb_g, $white->rgb_b]);
        $this->assertEqualsWithDelta(100.0, (float) $white->lab_l, 0.01);
        $this->assertNull(DB::table('color_families')->where('code', 'multicolor')->value('rgb_r'));

        // A row as the previous schema left it: a company colour with a tint and a typed reference, nothing derived.
        $id = DB::table('color_families')->insertGetId(['company_id' => $this->company->id, 'code' => 'c_legacy', 'name' => json_encode(['fr' => 'Bleu canard', 'en' => 'Bleu canard']),
            'hex_color' => '#0a6c74', 'pantone_reference' => '19-4052 TCX', 'sort_order' => 1000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Colors::completeDerived();

        $legacy = DB::table('color_families')->where('id', $id)->first();
        $this->assertSame(['c_legacy', '19-4052 TCX', 'user', 'custom', 10, 108, 116], [$legacy->code, $legacy->pantone_reference, $legacy->source, $legacy->color_system, $legacy->rgb_r, $legacy->rgb_g, $legacy->rgb_b]);
        $this->assertSame('Bleu canard', json_decode($legacy->name, true)['fr']);
        $this->assertNotNull($legacy->lab_l);
    }

    public function test_an_import_reports_what_it_did_and_running_it_again_changes_nothing(): void
    {
        $path = $this->file('pantone-coated.json', [
            ['pantone' => 't-100-c', 'hex' => '#f6eb61'],
            ['pantone' => 't-101-c', 'hex' => 'F7EA48'],
            ['pantone' => ' t-102-c ', 'hex' => '#abc'],
            ['pantone' => 't-100-c', 'hex' => '#F6EB61'],                               // listed twice
            ['pantone' => 't-101-c', 'hex' => '#000000'],                               // listed twice, another colour
            ['pantone' => 't-rgb', 'r' => '255', 'g' => '220', 'b' => '178'],           // colour given as r/g/b only
            ['pantone' => 't-odd', 'hex' => '#E5B887', 'r' => '229', 'g' => '184', 'b' => '143'], // the file contradicts itself
            ['hex' => '#112233'],                                                       // no reference
            ['pantone' => '', 'hex' => '#112233'],
            ['pantone' => 't-bad', 'hex' => 'not-a-colour'],
            ['pantone' => 't-empty'],
            ['pantone' => 't-half', 'r' => 12, 'g' => 300, 'b' => 4],
            'a string, not a record',
        ]);
        $importer = app(ColorCatalogueImporter::class);

        // A dry run reads everything and writes nothing.
        $dry = $importer->import($path, dryRun: true);
        $this->assertSame([5, 0, 0], [$dry['inserted'], $dry['updated'], $this->catalogueSize()]);

        $first = $importer->import($path);
        $this->assertSame(['coated', 13, 5, 0, 0, 2, 6, 1], [$first['collection'], $first['records'], $first['inserted'], $first['updated'], $first['unchanged'], $first['duplicates'], $first['invalid'], $first['warnings']]);
        $this->assertSame($first['records'], $first['inserted'] + $first['updated'] + $first['unchanged'] + $first['duplicates'] + $first['invalid']);
        $this->assertNotEmpty($first['issues']);

        $rows = Colors::catalogue()->orderBy('id')->get()->keyBy('reference_code');
        $this->assertSame(['t-100-c', 't-101-c', 't-102-c', 't-rgb', 't-odd'], $rows->keys()->all());
        // References are kept as written; colours are normalised; the first of two conflicting records wins.
        $this->assertSame(['#F6EB61', '#F7EA48', '#AABBCC', '#FFDCB2', '#E5B887'], $rows->pluck('hex_color')->values()->all());
        $this->assertSame([229, 184, 135], [$rows['t-odd']->rgb_r, $rows['t-odd']->rgb_g, $rows['t-odd']->rgb_b]);
        $row = $rows['t-100-c'];
        $this->assertSame([null, 'pantone', 'coated', 'import', 'unverified', 'pantone-coated.json'], [$row->company_id, $row->color_system, $row->collection, $row->source, $row->verification_status, $row->source_name]);
        $this->assertNotNull($row->lab_l);

        $second = $importer->import($path);
        $this->assertSame([0, 0, 5, 5], [$second['inserted'], $second['updated'], $second['unchanged'], $this->catalogueSize()]);

        // A corrected file updates the row it names and leaves the others alone.
        $third = $importer->import($this->file('pantone-coated.json', [['pantone' => 't-100-c', 'hex' => '#F6EB62'], ['pantone' => 't-103-c', 'hex' => '#C5A900']]));
        $this->assertSame([1, 1, 0, 6], [$third['inserted'], $third['updated'], $third['unchanged'], $this->catalogueSize()]);
        $this->assertSame($row->code, Colors::catalogue()->where('reference_code', 't-100-c')->value('code'));
        $this->assertSame('#F6EB62', Colors::catalogue()->where('reference_code', 't-100-c')->value('hex_color'));
    }

    public function test_the_same_reference_lives_in_several_collections(): void
    {
        $this->catalogue();

        $rows = Colors::catalogue()->where('reference_code', 't-871-c')->orderBy('collection')->get(['collection', 'hex_color']);
        $this->assertSame([['coated', '#84754E'], ['metallic', '#84764D']], $rows->map(fn ($row) => [$row->collection, $row->hex_color])->all());
        $this->assertSame(7, $this->catalogueSize());

        // The whole folder again: still seven.
        $this->artisan('colors:import', ['path' => $this->folder])->assertExitCode(0);
        $this->assertCount(7, Colors::catalogue()->pluck('id'));

        // Removing one collection leaves the other.
        $this->artisan('colors:remove-catalogue', ['--collection' => 'metallic'])->assertExitCode(0);
        $this->assertSame(['coated'], Colors::catalogue()->distinct()->pluck('collection')->all());
    }

    public function test_a_file_that_is_not_a_list_of_colours_is_refused_whole(): void
    {
        $importer = app(ColorCatalogueImporter::class);
        foreach (['{"pantone": "t-1", "hex": "#fff"', '{"pantone": "t-1", "hex": "#ffffff"}', '"text"', ''] as $index => $content) {
            try {
                $importer->import($this->file('pantone-coated.json', $content));
                $this->fail("Content {$index} was accepted.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('pantone-coated.json', $exception->getMessage());
            }
        }
        $this->assertSame(0, $this->catalogueSize());
        $this->artisan('colors:import', ['path' => $this->file('pantone-coated.json', '[')])->assertExitCode(1);

        // A missing file is an error, not an empty import; a file whose name says nothing needs its collection.
        foreach ([[$this->folder.'/absent.json', 'coated'], [$this->file('colours.json', [['pantone' => 't-1', 'hex' => '#ffffff']]), null], [$this->folder.'/colours.json', 'Not A Collection']] as [$path, $collection]) {
            try {
                $importer->import($path, $collection);
                $this->fail("{$path} was accepted.");
            } catch (InvalidArgumentException) {
                $this->assertDatabaseMissing('color_families', ['source' => 'import']);
            }
        }
        $this->assertSame(1, $importer->import($this->folder.'/colours.json', 'house_shades')['inserted']);
        $this->assertSame('house_shades', Colors::catalogue()->value('collection'));
    }

    public function test_the_catalogue_is_read_page_by_page_searched_and_filtered(): void
    {
        $this->catalogue();

        $page = $this->client()->getJson('/api/v1/colors/catalogue?per_page=3')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 7)->assertJsonPath('meta.last_page', 3)->assertJsonPath('data.0.is_catalogue', true)->assertJsonPath('data.0.is_custom', false)
            ->assertJsonPath('data.0.reference_code', 't-100-c')->assertJsonPath('data.0.collection', 'coated')->assertJsonPath('data.0.rgb', ['r' => 246, 'g' => 235, 'b' => 97])->json();
        $this->assertSame([['collection' => 'coated', 'colors' => 5], ['collection' => 'metallic', 'colors' => 2]], $page['meta']['collections']);
        $this->client()->getJson('/api/v1/colors/catalogue?per_page=3&page=3')->assertOk()->assertJsonCount(1, 'data');

        $references = fn (string $query) => array_column($this->client()->getJson('/api/v1/colors/catalogue?'.$query)->assertOk()->json('data'), 'reference_code');
        $this->assertSame(['t-871-c', 't-8001-c'], $references('collection=metallic'));
        $this->assertSame(['t-871-c', 't-871-c'], $references('search=871'));
        $this->assertSame(['t-871-c'], $references('search=871&collection=metallic'));
        $this->assertSame(['t-warm-gray-1-c'], $references('search=Warm+Gray'));
        $this->assertSame(['t-200-c'], $references('search=pantone+T-200'));
        $this->assertSame(['t-300-c'], $references('search=%23005eb8'));
        $this->assertSame(['t-300-c'], $references('search=005E'));
        $this->assertSame([], $references('search=100%25'));
        $this->client()->getJson('/api/v1/colors/catalogue?per_page=500')->assertUnprocessable();

        // One colour in detail.
        $code = $page['data'][0]['code'];
        $this->client()->getJson("/api/v1/colors/{$code}")->assertOk()->assertJsonPath('data.reference_code', 't-100-c')->assertJsonPath('data.source', 'import')->assertJsonPath('data.verification_status', 'unverified');
        $this->client()->getJson('/api/v1/colors/absent')->assertNotFound();
    }

    public function test_the_catalogue_is_not_offered_in_the_forms_and_cannot_be_modified(): void
    {
        $this->catalogue();
        $defaults = DB::table('color_families')->whereNull('company_id')->where('source', 'platform')->count();
        $code = Colors::catalogue()->where('reference_code', 't-200-c')->value('code');

        $this->client()->getJson('/api/v1/colors')->assertOk()->assertJsonCount($defaults, 'data');
        $this->assertCount($defaults, $this->client()->getJson('/api/v1/reference')->json('data.color_families'));

        // A catalogue colour is not a colour of the company: it cannot be put on a waste type, changed or deleted.
        $this->client()->postJson('/api/v1/waste-types', ['code' => 'TEST', 'name' => 'Test', 'waste_family' => DB::table('waste_families')->value('code'), 'color_family' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors(['color_family']);
        $this->client()->putJson("/api/v1/colors/{$code}", ['name' => 'Autre', 'hex_color' => '#000000'])->assertNotFound();
        $this->client()->deleteJson("/api/v1/colors/{$code}")->assertNotFound();
        $this->client()->deleteJson('/api/v1/colors/white')->assertNotFound();
        $this->assertSame('#BA0C2F', Colors::catalogue()->where('code', $code)->value('hex_color'));

        // Reading needs an account and the right to see the catalogue of the company.
        app('auth')->forgetGuards();
        $this->withoutToken()->getJson('/api/v1/colors/catalogue')->assertUnauthorized();
        $provider = $this->makeCompany('provider');
        $providerAdmin = $this->makeMember($provider, ['provider_admin' => null]);
        $this->client($providerAdmin, $provider)->getJson('/api/v1/colors/catalogue')->assertForbidden();
        $this->client($providerAdmin, $provider)->getJson('/api/v1/colors/match?hex=%23000000')->assertForbidden();
    }

    public function test_a_company_takes_a_catalogue_colour_into_its_own_list(): void
    {
        $this->catalogue();
        $coated = Colors::catalogue()->where('reference_code', 't-871-c')->where('collection', 'coated')->value('code');
        $metallic = Colors::catalogue()->where('reference_code', 't-871-c')->where('collection', 'metallic')->value('code');

        $taken = $this->client()->postJson("/api/v1/colors/catalogue/{$coated}/take")->assertCreated()
            ->assertJsonPath('data.is_custom', true)->assertJsonPath('data.is_catalogue', false)->assertJsonPath('data.color_system', 'pantone')->assertJsonPath('data.collection', 'coated')
            ->assertJsonPath('data.reference_code', 't-871-c')->assertJsonPath('data.hex_color', '#84754E')->assertJsonPath('data.name.fr', 'Pantone t-871-c')->json('data');
        $this->assertNotSame($coated, $taken['code']);

        // Asked again: the same colour, not a second one. The same reference of another collection is another colour.
        $this->client()->postJson("/api/v1/colors/catalogue/{$coated}/take")->assertOk()->assertJsonPath('data.code', $taken['code']);
        $other = $this->client()->postJson("/api/v1/colors/catalogue/{$metallic}/take")->assertCreated()->assertJsonPath('data.name.fr', 'Pantone t-871-c · metallic')->json('data');
        $this->assertSame(2, DB::table('color_families')->where('company_id', $this->company->id)->count());

        // Offered in the forms, usable on a waste type, and flagged in the catalogue.
        $this->assertContains($taken['code'], array_column($this->client()->getJson('/api/v1/reference')->json('data.color_families'), 'code'));
        $this->client()->postJson('/api/v1/waste-types', ['code' => 'TEST', 'name' => 'Test', 'waste_family' => DB::table('waste_families')->value('code'), 'color_family' => $taken['code']])->assertCreated();
        $this->assertSame($taken['code'], collect($this->client()->getJson('/api/v1/colors/catalogue?search=871&collection=coated')->json('data'))->firstWhere('code', $coated)['taken_as']);

        // Its name is the company's; its reference and tint stay those of the catalogue.
        $this->client()->putJson("/api/v1/colors/{$taken['code']}", ['name' => 'Or vieilli', 'hex_color' => '#000000', 'reference_code' => 'X'])->assertOk()
            ->assertJsonPath('data.name.fr', 'Or vieilli')->assertJsonPath('data.hex_color', '#84754E')->assertJsonPath('data.reference_code', 't-871-c');

        // Used by a waste type: withdrawn, not deleted. The unused one can go.
        $this->client()->deleteJson("/api/v1/colors/{$taken['code']}")->assertConflict()->assertJsonPath('code', 'COLOR_IN_USE');
        $this->client()->deleteJson("/api/v1/colors/{$other['code']}")->assertNoContent();
        $this->assertDatabaseMissing('color_families', ['code' => $other['code']]);
        $this->assertSame(7, $this->catalogueSize());

        // Another company sees the catalogue, not what this one took; an operator cannot take a colour.
        $stranger = $this->makeCompany();
        $strangerAdmin = $this->makeMember($stranger, ['client_admin' => null]);
        $this->assertNotContains($taken['code'], array_column($this->client($strangerAdmin, $stranger)->getJson('/api/v1/colors')->json('data'), 'code'));
        $this->assertNull(collect($this->client($strangerAdmin, $stranger)->getJson('/api/v1/colors/catalogue?search=871&collection=coated')->json('data'))->firstWhere('code', $coated)['taken_as']);
        $this->client($strangerAdmin, $stranger)->getJson("/api/v1/colors/{$taken['code']}")->assertNotFound();
        $this->client($strangerAdmin, $stranger)->deleteJson("/api/v1/colors/{$taken['code']}")->assertNotFound();
        $this->client($strangerAdmin, $stranger)->postJson("/api/v1/colors/catalogue/{$coated}/take")->assertCreated();
        $operator = $this->makeMember($this->company, ['workshop_operator' => $this->makeSite($this->company, 'NAB')]);
        $this->client($operator)->postJson("/api/v1/colors/catalogue/{$metallic}/take")->assertForbidden();
        $this->client($operator)->deleteJson("/api/v1/colors/{$taken['code']}")->assertForbidden();

        // The catalogue goes away: what companies took stays as it is.
        $this->artisan('colors:remove-catalogue')->assertExitCode(0);
        $this->assertSame(0, $this->catalogueSize());
        $this->assertDatabaseHas('color_families', ['code' => $taken['code'], 'hex_color' => '#84754E', 'catalog_color_id' => null]);
    }

    public function test_a_custom_colour_is_given_as_hex_or_rgb_with_an_optional_code(): void
    {
        $created = $this->client()->postJson('/api/v1/colors', ['name' => 'Bleu maison', 'reference_code' => 'BM-01', 'rgb' => ['r' => 15, 'g' => 76, 'b' => 129]])->assertCreated()
            ->assertJsonPath('data.hex_color', '#0F4C81')->assertJsonPath('data.rgb', ['r' => 15, 'g' => 76, 'b' => 129])->assertJsonPath('data.reference_code', 'BM-01')
            ->assertJsonPath('data.color_system', 'custom')->assertJsonPath('data.source', 'user')->assertJsonPath('data.is_catalogue', false)->json('data');
        $this->assertEqualsWithDelta(31.0, $created['lab']['l'], 2.0);

        $this->client()->postJson('/api/v1/colors', ['name' => 'Court', 'hex_color' => 'abc'])->assertCreated()->assertJsonPath('data.hex_color', '#AABBCC');
        $this->client()->postJson('/api/v1/colors', ['name' => 'Accord', 'hex_color' => '#0f4c81', 'rgb' => ['r' => 15, 'g' => 76, 'b' => 129]])->assertCreated();
        $this->client()->postJson('/api/v1/colors', ['name' => 'Sans teinte'])->assertCreated()->assertJsonPath('data.hex_color', null)->assertJsonPath('data.rgb', null)->assertJsonPath('data.lab', null);

        $this->client()->postJson('/api/v1/colors', ['name' => 'Désaccord', 'hex_color' => '#0F4C81', 'rgb' => ['r' => 0, 'g' => 0, 'b' => 0]])->assertUnprocessable()->assertJsonValidationErrors(['rgb']);
        $this->client()->postJson('/api/v1/colors', ['name' => 'Trop', 'rgb' => ['r' => 256, 'g' => 0, 'b' => 0]])->assertUnprocessable()->assertJsonValidationErrors(['rgb.r']);
        $this->client()->postJson('/api/v1/colors', ['name' => 'Incomplet', 'rgb' => ['r' => 1]])->assertUnprocessable()->assertJsonValidationErrors(['rgb.g', 'rgb.b']);
        $this->client()->postJson('/api/v1/colors', ['name' => 'Faux', 'hex_color' => '#12345'])->assertUnprocessable()->assertJsonValidationErrors(['hex_color']);
        $this->client()->postJson('/api/v1/colors', ['name' => 'Même code', 'reference_code' => 'BM-01'])->assertUnprocessable()->assertJsonValidationErrors(['reference_code']);

        // Search in the company's list by name, code or HEX.
        $names = fn (string $search) => array_map(fn (array $color) => $color['name']['fr'], $this->client()->getJson('/api/v1/colors?search='.urlencode($search))->assertOk()->json('data'));
        $this->assertSame(['Bleu maison'], $names('bm-01'));
        $this->assertSame(['Bleu maison', 'Accord'], $names('#0F4C81'));
        $this->assertSame(['Court'], $names('cour'));

        // Another company may use the same code; changing a colour recomputes what follows from its tint.
        $stranger = $this->makeCompany();
        $this->client($this->makeMember($stranger, ['client_admin' => null]), $stranger)->postJson('/api/v1/colors', ['name' => 'Bleu maison', 'reference_code' => 'BM-01'])->assertCreated();
        $this->client()->putJson("/api/v1/colors/{$created['code']}", ['name' => 'Bleu maison', 'reference_code' => 'BM-01', 'hex_color' => '#ffffff'])->assertOk()
            ->assertJsonPath('data.rgb', ['r' => 255, 'g' => 255, 'b' => 255])->assertJsonPath('data.reference_code', 'BM-01');
        $this->client()->deleteJson("/api/v1/colors/{$created['code']}")->assertNoContent();
    }

    public function test_the_nearest_catalogue_colours_are_suggested_closest_first(): void
    {
        $this->catalogue();

        $response = $this->client()->getJson('/api/v1/colors/match?hex=005eb9&limit=3')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.approximate', true)->assertJsonPath('meta.formula', 'CIEDE2000')->assertJsonPath('meta.hex', '#005EB9')
            ->assertJsonPath('data.0.reference_code', 't-300-c')->json('data');
        $distances = array_column($response, 'delta_e');
        $this->assertLessThan(1, $distances[0]);
        $sorted = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances);

        // An exact colour is at distance zero; the collection narrows the search; nothing is changed by asking.
        $this->client()->getJson('/api/v1/colors/match?hex=%2384764D&collection=metallic&limit=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.collection', 'metallic')->assertJsonPath('data.0.delta_e', fn ($distance) => (float) $distance === 0.0);
        $this->client()->getJson('/api/v1/colors/match?hex=blue')->assertUnprocessable()->assertJsonValidationErrors(['hex']);
        $this->client()->getJson('/api/v1/colors/match')->assertUnprocessable()->assertJsonValidationErrors(['hex']);
        $this->assertSame(0, DB::table('color_families')->where('company_id', $this->company->id)->count());
    }
}
