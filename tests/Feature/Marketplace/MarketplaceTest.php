<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Providers\Models\ProviderAcceptedWaste;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Waste\Actions\CreateWasteLot;
use App\Modules\Waste\DTOs\CreateWasteLotData;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The bourse (M4-06): who sees a listing, the negotiation in turns, privacy between buyers, the reserved lots, and the
 * deal that becomes a pickup priced as agreed.
 */
class MarketplaceTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $industrial;

    private Site $site;

    private User $manager;

    /** @var array<string, array{Company, User}> */
    private array $providers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->industrial = $this->makeCompany();
        $this->site = $this->makeSite($this->industrial, 'SFX');
        $this->manager = $this->makeMember($this->industrial, ['environment_manager' => $this->site]);
        foreach (['a', 'b'] as $key) {
            $this->providers[$key] = $this->provider(acceptsTheWaste: true);
        }
        $this->providers['other'] = $this->provider(acceptsTheWaste: false);
    }

    public function test_a_published_listing_is_read_by_the_eligible_providers_only_without_the_seller(): void
    {
        $listing = $this->publish([$this->lot('120'), $this->lot(null)]);

        $this->as('a')->getJson('/api/v1/marketplace/catalog')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $listing)->assertJsonPath('data.0.seller', null)
            ->assertJsonPath('data.0.totals.weight_kg', '120.000')->assertJsonPath('data.0.totals.unweighed_lots', 1)
            ->assertJsonPath('data.0.can_offer', true);
        $this->as('a')->getJson("/api/v1/marketplace/catalog/{$listing}")->assertOk()->assertJsonCount(2, 'data.lots')
            ->assertJsonPath('data.location.city', null)->assertJsonMissingPath('data.lots.0.zone');
        // A provider that does not take this waste does not see the listing, nor can it bid.
        $this->as('other')->getJson('/api/v1/marketplace/catalog')->assertOk()->assertJsonCount(0, 'data');
        $this->as('other')->getJson("/api/v1/marketplace/catalog/{$listing}")->assertNotFound();
        $this->as('other')->postJson("/api/v1/marketplace/catalog/{$listing}/offers", $this->terms('80'))->assertNotFound();
        // A draft is nobody's business but the seller's; an industrial does not bid.
        $five = $this->lot('5');
        $draft = $this->asManager()->postJson('/api/v1/marketplace/listings', ['site' => $this->site->ulid, 'lots' => [$five]])->assertCreated()->json('data.ulid');
        $this->as('a')->getJson("/api/v1/marketplace/catalog/{$draft}")->assertNotFound();
        $this->asManager()->getJson('/api/v1/marketplace/catalog')->assertNotFound();

        // The seller may choose to show its name.
        $this->asManager()->putJson("/api/v1/marketplace/listings/{$listing}", ['show_seller' => true])->assertOk();
        $this->as('a')->getJson("/api/v1/marketplace/catalog/{$listing}")->assertJsonPath('data.seller.legal_name', $this->industrial->legal_name);
    }

    public function test_negotiation_in_turns_until_the_deal_becomes_a_pickup_priced_at_reception(): void
    {
        [$first, $second] = [$this->lot('600'), $this->lot('400')];
        $listing = $this->publish([$first, $second]);

        $negotiation = $this->as('a')->postJson("/api/v1/marketplace/catalog/{$listing}/offers", $this->terms('80'))
            ->assertCreated()->assertJsonPath('data.status', 'awaiting_seller')->assertJsonPath('data.your_turn', false)
            ->assertJsonPath('data.proposals.0.amount', '80.000')->json('data.ulid');
        // Not its turn: the buyer cannot answer its own offer, nor open a second negotiation.
        $this->as('a')->postJson("/api/v1/marketplace/negotiations/{$negotiation}/counter", $this->terms('90'))->assertStatus(409)->assertJsonPath('code', 'PROPOSAL_NOT_YOUR_TURN');
        $this->as('a')->postJson("/api/v1/marketplace/catalog/{$listing}/offers", $this->terms('85'))->assertStatus(409)->assertJsonPath('code', 'NEGOTIATION_OPEN');

        $this->asManager()->getJson("/api/v1/marketplace/listings/{$listing}")->assertOk()
            ->assertJsonPath('data.awaiting_seller', 1)->assertJsonPath('data.negotiations.0.buyer.legal_name', $this->providers['a'][0]->legal_name);
        $counter = $this->asManager()->postJson("/api/v1/marketplace/negotiations/{$negotiation}/counter", $this->terms('110'))
            ->assertOk()->assertJsonPath('data.status', 'awaiting_buyer')->json('data.proposals.1.ulid');
        // What is accepted is what was read: the first offer has been answered since.
        $offer = DB::table('marketplace_proposals')->where('sequence', 1)->value('ulid');
        $this->as('a')->postJson("/api/v1/marketplace/negotiations/{$negotiation}/accept", ['proposal' => $offer])->assertStatus(409)->assertJsonPath('code', 'PROPOSAL_SUPERSEDED');

        // Another buyer is negotiating too; it will be told the lots went to someone else — not at what price.
        $lost = $this->as('b')->postJson("/api/v1/marketplace/catalog/{$listing}/offers", $this->terms('95'))->assertCreated()->json('data.ulid');

        $this->as('a')->postJson("/api/v1/marketplace/negotiations/{$negotiation}/accept", ['proposal' => $counter])
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.commission_pct', '0.00')
            ->assertJsonPath('data.listing.seller.legal_name', $this->industrial->legal_name);
        $this->as('b')->getJson("/api/v1/marketplace/negotiations/{$lost}")->assertOk()->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_reason', 'sold_to_another')->assertJsonPath('data.pickup', null)->assertJsonPath('data.listing.seller', null);
        $this->assertSame('sold', DB::table('marketplace_listings')->value('status'));

        // The deal is a pickup to the buyer, now a partner, which the seller sends as any request.
        $pickup = DB::table('pickups')->first();
        $this->assertSame((int) $this->providers['a'][0]->id, (int) $pickup->provider_company_id);
        $this->assertSame('draft', $pickup->status);
        $this->assertTrue(DB::table('provider_partnerships')->where('provider_company_id', $this->providers['a'][0]->id)->where('status', 'active')->exists());
        $this->asManager()->getJson("/api/v1/pickups/{$pickup->ulid}")->assertOk()->assertJsonPath('data.marketplace_deal.unit_price', '110.000')
            ->assertJsonPath('data.marketplace_deal.pricing_mode', 'per_tonne');
        $this->asManager()->postJson("/api/v1/pickups/{$pickup->ulid}/submit")->assertOk();
        $this->as('a')->postJson("/api/v1/pickups/{$pickup->ulid}/confirm", ['confirmed_date' => now()->addDays(2)->toDateString()])->assertOk();
        $this->asManager()->postJson("/api/v1/pickups/{$pickup->ulid}/collect", ['lots' => [['lot' => $first], ['lot' => $second]]])->assertOk();
        $numbers = DB::table('pickup_lots')->orderBy('id')->pluck('lot_number_snapshot')->all();
        $this->as('a')->postJson("/api/v1/pickups/{$pickup->ulid}/receive", ['lots' => [
            ['lot_number' => $numbers[0], 'received_weight_kg' => '590'], ['lot_number' => $numbers[1], 'received_weight_kg' => '400'],
        ]])->assertOk();

        // 110 per tonne, the buyer pays: 0.59 t → 64.900, 0.4 t → 44.000, on what was received.
        $this->assertSame(['64.900', '44.000'], DB::table('pickup_lots')->orderBy('id')->pluck('net_amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['received', 'received'], DB::table('pickup_lots')->orderBy('id')->pluck('price_basis')->all());
    }

    public function test_a_buyer_never_reads_another_buyers_negotiation(): void
    {
        $listing = $this->publish([$this->lot('50')]);
        $mine = $this->as('a')->postJson("/api/v1/marketplace/catalog/{$listing}/offers", $this->terms('40'))->json('data.ulid');
        $this->as('b')->postJson("/api/v1/marketplace/catalog/{$listing}/offers", $this->terms('45'))->assertCreated();

        $this->as('b')->getJson("/api/v1/marketplace/negotiations/{$mine}")->assertNotFound();
        $this->as('b')->postJson("/api/v1/marketplace/negotiations/{$mine}/withdraw")->assertNotFound();
        $this->as('b')->getJson("/api/v1/marketplace/catalog/{$listing}")->assertOk()->assertJsonPath('data.my_negotiation.last_proposal.unit_price', '45.000')
            ->assertJsonMissingPath('data.negotiations');
        // Another industrial does not reach the seller's listing.
        $stranger = $this->makeCompany();
        $this->withToken($this->tokenFor($this->makeMember($stranger, ['client_admin' => null]), $stranger))
            ->getJson("/api/v1/marketplace/listings/{$listing}")->assertNotFound();
    }

    public function test_a_listed_lot_is_reserved_for_the_deal(): void
    {
        $lot = $this->lot('30');
        $listing = $this->publish([$lot]);

        $this->asManager()->postJson('/api/v1/marketplace/listings', ['site' => $this->site->ulid, 'lots' => [$lot]])->assertStatus(409)->assertJsonPath('code', 'LOT_LISTED');
        $this->asManager()->postJson("/api/v1/lots/{$lot}/split", ['outputs' => [['net_weight_kg' => '10'], ['net_weight_kg' => '20']]])->assertStatus(409)->assertJsonPath('code', 'LOT_LISTED');
        $this->asManager()->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $this->providers['a'][0]->ulid,
            'requested_date' => now()->addDay()->toDateString(), 'lots' => [$lot]])->assertStatus(409)->assertJsonPath('code', 'LOT_LISTED');

        // Cancelled, the lot is free again and the buyers still negotiating are told.
        $negotiation = $this->as('a')->postJson("/api/v1/marketplace/catalog/{$listing}/offers", $this->terms('20'))->json('data.ulid');
        $this->asManager()->postJson("/api/v1/marketplace/listings/{$listing}/cancel", ['reason' => 'Vendu hors plateforme'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as('a')->getJson("/api/v1/marketplace/negotiations/{$negotiation}")->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed_reason', 'listing_cancelled');
        $this->asManager()->postJson("/api/v1/lots/{$lot}/split", ['outputs' => [['net_weight_kg' => '10'], ['net_weight_kg' => '20']]])->assertCreated();
    }

    public function test_a_proposal_made_on_lots_that_changed_since_is_answered_not_accepted(): void
    {
        $lot = $this->lot('100');
        $listing = $this->publish([$lot]);
        $negotiation = $this->as('a')->postJson("/api/v1/marketplace/catalog/{$listing}/offers", $this->terms('70'))->json('data.ulid');
        $offer = DB::table('marketplace_proposals')->value('ulid');

        $this->asManager()->postJson("/api/v1/lots/{$lot}/weigh", ['net_weight_kg' => '80'])->assertOk();
        $this->asManager()->getJson("/api/v1/marketplace/negotiations/{$negotiation}")->assertJsonPath('data.proposals.0.outdated', true);
        $this->asManager()->postJson("/api/v1/marketplace/negotiations/{$negotiation}/accept", ['proposal' => $offer])->assertStatus(409)->assertJsonPath('code', 'PROPOSAL_OUTDATED');
        $this->asManager()->postJson("/api/v1/marketplace/negotiations/{$negotiation}/counter", $this->terms('70'))->assertOk();
    }

    public function test_accepting_needs_its_own_permission_and_a_buyer_still_eligible(): void
    {
        $listing = $this->publish([$this->lot('100')]);
        $negotiation = $this->as('a')->postJson("/api/v1/marketplace/catalog/{$listing}/offers", $this->terms('70'))->json('data.ulid');
        $offer = DB::table('marketplace_proposals')->value('ulid');

        $viewer = $this->makeMember($this->industrial, ['management_viewer' => $this->site]);
        $this->withToken($this->tokenFor($viewer, $this->industrial))->getJson("/api/v1/marketplace/negotiations/{$negotiation}")->assertOk();
        $this->withToken($this->tokenFor($viewer, $this->industrial))->postJson("/api/v1/marketplace/negotiations/{$negotiation}/accept", ['proposal' => $offer])->assertForbidden();

        DB::table('provider_accreditations')->where('company_id', $this->providers['a'][0]->id)->update(['expires_on' => now()->subDay()]);
        $this->asManager()->postJson("/api/v1/marketplace/negotiations/{$negotiation}/accept", ['proposal' => $offer])->assertStatus(409)->assertJsonPath('code', 'BUYER_NOT_ELIGIBLE');
    }

    /**
     * @param  list<string>  $lots
     */
    private function publish(array $lots): string
    {
        $listing = $this->asManager()->postJson('/api/v1/marketplace/listings', ['site' => $this->site->ulid, 'lots' => $lots, 'description' => 'Chutes de coupe propres'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.ulid');
        $this->asManager()->postJson("/api/v1/marketplace/listings/{$listing}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        return $listing;
    }

    /**
     * @return array<string, string>
     */
    private function terms(string $price): array
    {
        return ['pricing_mode' => 'per_tonne', 'unit_price' => $price, 'direction' => 'revenue'];
    }

    /**
     * @return array{Company, User}
     */
    private function provider(bool $acceptsTheWaste): array
    {
        $company = $this->makeCompany('provider');
        $user = $this->makeMember($company, ['provider_admin' => null]);
        $this->tenant()->runAs($company, function () use ($company, $acceptsTheWaste) {
            ProviderProfile::query()->create(['is_collector' => true, 'is_recycler' => true, 'is_published' => true, 'published_at' => now()]);
            ProviderAcceptedWaste::query()->create(['treatment_channel_id' => DB::table('treatment_channels')->value('id'),
                'waste_family_id' => $acceptsTheWaste ? DB::table('waste_families')->orderBy('id')->value('id') : DB::table('waste_families')->orderByDesc('id')->value('id')]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $company->id, 'disk' => 's3',
                'path' => 'tenants/p'.$company->id.'/agrement.pdf', 'original_name' => 'agrement.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf',
                'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditation', 'created_at' => now(), 'updated_at' => now()]);
            $type = DB::table('accreditation_types')->where('code', 'TEST_COLLECT')->value('id')
                ?? DB::table('accreditation_types')->insertGetId(['country_id' => DB::table('countries')->value('id'), 'code' => 'TEST_COLLECT', 'name' => '{}']);
            ProviderAccreditation::query()->create(['accreditation_type_id' => $type, 'reference_number' => 'AG-'.$company->id, 'valid_from' => now()->subYear(),
                'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });

        return [$company, $user];
    }

    private function lot(?string $kg): string
    {
        return $this->tenant()->runAs($this->industrial, function () use ($kg) {
            $zone = Zone::query()->firstOrCreate(['code' => 'AIRE'], ['site_id' => $this->site->id, 'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']);
            $type = WasteType::query()->firstOrCreate(['code' => 'CHUTES'], ['name' => 'Chutes', 'waste_family_id' => DB::table('waste_families')->orderBy('id')->value('id'),
                'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'), 'activated_at' => now()]);

            return app(CreateWasteLot::class)->handle(new CreateWasteLotData($this->site->ulid, $zone->ulid, $type->ulid, $kg), $this->manager)->ulid;
        });
    }

    private function asManager(): static
    {
        return $this->withToken($this->tokenFor($this->manager, $this->industrial));
    }

    private function as(string $provider): static
    {
        return $this->withToken($this->tokenFor($this->providers[$provider][1], $this->providers[$provider][0]));
    }
}
