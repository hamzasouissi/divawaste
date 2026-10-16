<?php

namespace Tests\Feature\Pickups;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Identity\Models\User;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Waste\Support\ToleranceRules;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Tolerances per waste type and handling method, in the unit of the waste, and what happens when they are exceeded.
 */
class ToleranceRulesTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Company $industrial;

    private Company $provider;

    private Site $site;

    private User $manager;

    private User $admin;

    private User $providerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        // In this company the environment manager explains gaps; only the administrator may accept a blocking one.
        DB::table('role_has_permissions')->where('permission_id', DB::table('permissions')->where('name', 'pickups.authorize_variance')->value('id'))
            ->where('role_id', DB::table('roles')->where('name', 'environment_manager')->value('id'))->delete();

        $this->industrial = $this->makeCompany();
        $this->provider = $this->makeCompany('provider');
        $this->site = $this->makeSite($this->industrial, 'SFX');
        $this->manager = $this->makeMember($this->industrial, ['environment_manager' => $this->site]);
        $this->admin = $this->makeMember($this->industrial, ['client_admin' => null]);
        $this->providerUser = $this->makeMember($this->provider, ['provider_admin' => null]);

        $this->tenant()->runAs($this->provider, function () {
            ProviderProfile::query()->create(['is_collector' => true, 'is_recycler' => true, 'is_published' => true, 'published_at' => now()]);
            $file = DB::table('stored_files')->insertGetId(['ulid' => strtoupper((string) Str::ulid()), 'company_id' => $this->provider->id, 'disk' => 's3',
                'path' => 'tenants/p/agrement.pdf', 'original_name' => 'agrement.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf',
                'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'purpose' => 'accreditation', 'created_at' => now(), 'updated_at' => now()]);
            $type = DB::table('accreditation_types')->insertGetId(['country_id' => DB::table('countries')->value('id'), 'code' => 'TN_COLLECT', 'name' => '{}']);
            ProviderAccreditation::query()->create(['accreditation_type_id' => $type, 'reference_number' => 'AG-1', 'valid_from' => now()->subYear(),
                'expires_on' => now()->addYear(), 'stored_file_id' => $file, 'review_status' => 'approved']);
        });
        $this->as($this->admin)->postJson('/api/v1/provider-partnerships', ['provider' => $this->provider->ulid])->assertCreated();
        $this->tenant()->runAs($this->industrial, fn () => Zone::query()->create(['code' => 'AIRE', 'site_id' => $this->site->id,
            'zone_type_id' => DB::table('zone_types')->value('id'), 'name' => 'Aire']));
    }

    public function test_rules_are_configured_by_who_manages_waste_types_validated_and_audited(): void
    {
        $type = $this->type('CHUTES', 'kg');
        $rule = ['waste_type' => $type, 'treatment_channel' => 'recycling', 'warning_pct' => '3', 'blocking_pct' => '8', 'direction' => 'both', 'basis' => 'operational'];

        $this->as($this->makeMember($this->industrial, ['workshop_operator' => $this->site]))->postJson('/api/v1/tolerance-rules', $rule)->assertForbidden();
        // The blocking limit stands above the warning one; a legal requirement names its text; one rule per subject.
        $this->as($this->admin)->postJson('/api/v1/tolerance-rules', ['blocking_pct' => '2'] + $rule)->assertStatus(422)->assertJsonValidationErrors('blocking_pct');
        $this->as($this->admin)->postJson('/api/v1/tolerance-rules', ['basis' => 'legal'] + $rule)->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->as($this->admin)->postJson('/api/v1/tolerance-rules', ['warning_pct' => null, 'blocking_pct' => null] + $rule)->assertStatus(422);

        $ulid = $this->as($this->admin)->postJson('/api/v1/tolerance-rules', $rule)->assertCreated()
            ->assertJsonPath('data.waste_type.code', 'CHUTES')->assertJsonPath('data.waste_type.unit', 'kg')->assertJsonPath('data.warning_pct', '3.00')->json('data.ulid');
        $this->as($this->admin)->postJson('/api/v1/tolerance-rules', $rule)->assertStatus(422)->assertJsonValidationErrors('waste_type');

        $this->as($this->admin)->putJson("/api/v1/tolerance-rules/{$ulid}", ['warning_pct' => '4', 'basis' => 'legal', 'reference' => 'Décret 2005-3395, art. 4'] + $rule)
            ->assertOk()->assertJsonPath('data.warning_pct', '4.00')->assertJsonPath('data.basis', 'legal');
        $this->as($this->admin)->getJson('/api/v1/tolerance-rules')->assertOk()->assertJsonCount(1, 'data')
            // Nothing configured for the rest: the 5 % of the cahier, as a warning only.
            ->assertJsonPath('meta.default.source', 'default')->assertJsonPath('meta.default.warning_pct', '5.00')->assertJsonPath('meta.default.blocking_pct', null);

        // Another company neither sees nor touches the rule.
        $other = $this->makeCompany();
        $stranger = $this->makeMember($other, ['client_admin' => null]);
        $this->withToken($this->tokenFor($stranger, $other))->getJson('/api/v1/tolerance-rules')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->tokenFor($stranger, $other))->putJson("/api/v1/tolerance-rules/{$ulid}", $rule)->assertNotFound();
        $this->withToken($this->tokenFor($stranger, $other))->postJson('/api/v1/tolerance-rules', $rule)->assertStatus(422);

        $this->as($this->admin)->deleteJson("/api/v1/tolerance-rules/{$ulid}")->assertNoContent();
        $events = DB::table('audit_logs')->where('auditable_ulid', $ulid)->orderBy('id')->get(['event', 'old_values', 'new_values', 'actor_user_id']);
        $this->assertSame(['created', 'updated', 'deleted'], $events->pluck('event')->all());
        $this->assertSame($this->admin->id, (int) $events[1]->actor_user_id);
        $this->assertStringContainsString('3.00', (string) $events[1]->old_values);
        $this->assertStringContainsString('Décret', (string) $events[1]->new_values);
    }

    public function test_the_most_precise_rule_applies(): void
    {
        $type = $this->type('CHUTES', 'kg');
        $typeId = (int) DB::table('waste_types')->where('ulid', $type)->value('id');
        $channel = (int) DB::table('treatment_channels')->where('code', 'recycling')->value('id');
        $other = (int) DB::table('treatment_channels')->where('code', '!=', 'recycling')->value('id');
        $resolve = fn (?int $t, ?int $c) => app(ToleranceRules::class)->resolve($this->industrial->id, $t, $c);
        $add = fn (array $rule) => $this->as($this->admin)->postJson('/api/v1/tolerance-rules', $rule + ['direction' => 'both', 'basis' => 'operational'])->assertCreated();

        $this->assertSame(['default', '5.00'], [$resolve($typeId, $channel)['source'], $resolve($typeId, $channel)['warning_pct']]);
        $this->as($this->admin)->putJson('/api/v1/company', ['weight_variance_threshold_pct' => '7'] + $this->companyPayload());
        $this->assertSame(['company_setting', '7.00'], [$resolve($typeId, $channel)['source'], $resolve($typeId, $channel)['warning_pct']]);

        $add(['warning_pct' => '6']);
        $this->assertSame('company', $resolve($typeId, $channel)['source']);
        $add(['treatment_channel' => 'recycling', 'warning_pct' => '4']);
        $this->assertSame(['channel', '4.00'], [$resolve($typeId, $channel)['source'], $resolve($typeId, $channel)['warning_pct']]);
        $add(['waste_type' => $type, 'warning_pct' => '3']);
        $this->assertSame('type', $resolve($typeId, $channel)['source']);
        $add(['waste_type' => $type, 'treatment_channel' => 'recycling', 'warning_pct' => '2', 'blocking_pct' => '9']);
        $this->assertSame(['type_channel', '2.00', '9.00', 'recycling'], array_values(array_intersect_key($resolve($typeId, $channel), array_flip(['source', 'warning_pct', 'blocking_pct', 'channel']))));

        // Handled another way, the same type falls back to its own rule; another type to the rule of the company.
        $this->assertSame(['type', '3.00'], [$resolve($typeId, $other)['source'], $resolve($typeId, $other)['warning_pct']]);
        $this->assertSame('company', $resolve(null, $other)['source']);
        // A rule switched off no longer applies.
        DB::table('waste_tolerance_rules')->whereNotNull('waste_type_id')->update(['is_active' => false]);
        $this->assertSame('channel', $resolve($typeId, $channel)['source']);

        $this->as($this->manager)->getJson("/api/v1/tolerance-rules/resolve?waste_type={$type}&treatment_channel=recycling")->assertOk()->assertJsonPath('data.source', 'channel');
    }

    public function test_waste_counted_in_pieces_is_declared_and_compared_in_pieces(): void
    {
        $cones = $this->type('CONES', 'piece');
        $this->as($this->admin)->getJson("/api/v1/waste-types/{$cones}")->assertJsonPath('data.measure_dimension', 'count')->assertJsonPath('data.measurement_method', 'counted');
        $base = ['site' => $this->site->ulid, 'zone' => $this->zone(), 'waste_type' => $cones];

        // A counted type needs a count: a weight alone, or a quantity in kilograms, is refused.
        $this->as($this->manager)->postJson('/api/v1/lots', $base + ['net_weight_kg' => '12'])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->as($this->manager)->postJson('/api/v1/lots', $base + ['quantity' => '100', 'unit' => 'kg'])->assertStatus(422)->assertJsonValidationErrors('quantity');
        // Nobody weighed it and the type states no weight per piece: the weight is unknown, not invented.
        $lot = $this->as($this->manager)->postJson('/api/v1/lots', $base + ['quantity' => '100', 'unit' => 'piece'])->assertCreated()
            ->assertJsonPath('data.weight_basis', 'unknown')->assertJsonPath('data.net_weight_kg', null)->assertJsonPath('data.quantity', '100.000')->json('data');
        // With a weight per piece on the type, the weight is computed and said to be.
        DB::table('waste_types')->where('ulid', $cones)->update(['unit_weight_kg' => '0.350']);
        $this->as($this->manager)->postJson('/api/v1/lots', $base + ['quantity' => '40', 'unit' => 'piece'])->assertCreated()
            ->assertJsonPath('data.weight_basis', 'computed')->assertJsonPath('data.net_weight_kg', '14.000');
        // A counted lot is not split by weight.
        $this->as($this->manager)->postJson("/api/v1/lots/{$lot['ulid']}/split", ['outputs' => [['net_weight_kg' => '1'], ['net_weight_kg' => '1']]])
            ->assertStatus(409)->assertJsonPath('code', 'LOT_NOT_WEIGHED');

        $this->rule(['waste_type' => $cones, 'warning_pct' => '5', 'blocking_pct' => '10']);
        $pickup = $this->collected([$lot['ulid']]);
        $this->as($this->manager)->getJson("/api/v1/pickups/{$pickup}")->assertJsonPath('data.lots.0.measure_dimension', 'count')
            ->assertJsonPath('data.lots.0.unit', 'piece')->assertJsonPath('data.lots.0.declared_quantity', '100.000')->assertJsonPath('data.lots.0.departure_quantity', '100.000');

        // A weight cannot stand for a count.
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $lot['lot_number'], 'received_weight_kg' => '36']]])
            ->assertStatus(409)->assertJsonPath('code', 'MEASURE_REQUIRED');
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $lot['lot_number'], 'received_quantity' => '108']]])
            ->assertOk()->assertJsonPath('data.variance_flagged', true)->assertJsonPath('data.tolerance_status', 'warning')
            ->assertJsonPath('data.lots.0.received_quantity', '108.000')->assertJsonPath('data.lots.0.tolerance_status', 'warning')
            ->assertJsonPath('data.lots.0.tolerance.unit', 'piece')->assertJsonPath('data.lots.0.tolerance.reference_quantity', '100.000')
            ->assertJsonPath('data.lots.0.tolerance.difference', '8.000')->assertJsonPath('data.lots.0.tolerance.pct', '8.00')
            ->assertJsonPath('data.lots.0.tolerance.warning_pct', '5.00')->assertJsonPath('data.lots.0.tolerance.source', 'type')
            // No weight was compared: none is reported.
            ->assertJsonPath('data.lots.0.weight_variance_pct', null)->assertJsonPath('data.lots.0.received_weight_kg', null);

        // Nothing of this pickup was weighed: it has no weight at departure nor on arrival — not 0 kg — and says how
        // many lots that is.
        $this->as($this->manager)->getJson("/api/v1/pickups/{$pickup}")->assertJsonPath('data.departure_weight_kg', null)->assertJsonPath('data.received_weight_kg', null)
            ->assertJsonPath('data.weight_variance_kg', null)->assertJsonPath('data.departure_unweighed_lots', 1)->assertJsonPath('data.received_unweighed_lots', 1);
        // The messages each party received speak in pieces too, in both languages, and never of "0 kg".
        $said = fn (string $type) => json_decode((string) DB::table('notifications')->where('type', $type)->value('data'), true)['params'];
        $this->assertSame(['100 pièce(s)', '100 piece(s)'], [$said('pickup.requested')['quantity'], $said('pickup.requested')['quantity_en']]);
        $this->assertSame('100 pièce(s)', $said('pickup.collected')['quantity']);
        $this->assertSame('108 pièce(s)', $said('pickup.received')['quantity']);
        $this->assertSame([1, '108 pièce(s)', '100 pièce(s)'], [$said('pickup.variance_flagged')['flagged'], $said('pickup.variance_flagged')['received'], $said('pickup.variance_flagged')['departure']]);

        // What was declared, what left and what arrived are three figures; none overwrote another.
        $line = DB::table('pickup_lots')->first();
        $this->assertSame(['100.000', '100.000', '108.000', '8.000'], [$line->declared_quantity, $line->departure_quantity, $line->received_quantity, $line->quantity_variance]);
        $this->assertSame('100.000', DB::table('waste_lots')->where('ulid', $lot['ulid'])->value('quantity'));

        // A warning is explained by who closes pickups.
        $this->as($this->manager)->postJson("/api/v1/pickups/{$pickup}/acknowledge-variance", ['comment' => 'Cônes ajoutés au chargement.'])
            ->assertOk()->assertJsonPath('data.variance_decision', 'acknowledged');
    }

    public function test_each_lot_is_judged_by_the_rule_of_its_type_and_handling_and_keeps_it(): void
    {
        [$scraps, $cardboard] = [$this->type('CHUTES', 'kg'), $this->type('CARTON', 'kg', 'energy_recovery')];
        $this->rule(['waste_type' => $scraps, 'warning_pct' => '2']);
        $tight = $this->rule(['waste_type' => $cardboard, 'treatment_channel' => 'energy_recovery', 'warning_pct' => '10', 'direction' => 'excess']);
        $this->rule(['waste_type' => $cardboard, 'warning_pct' => '1']);
        [$a, $b] = [$this->lot($scraps, '100'), $this->lot($cardboard, '100')];
        $pickup = $this->collected([$a['ulid'], $b['ulid']]);

        // The same 3 % gap: above the 2 % of the scraps, inside the 10 % of cardboard sent to energy recovery.
        $response = $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [
            ['lot_number' => $a['lot_number'], 'received_weight_kg' => '103'], ['lot_number' => $b['lot_number'], 'received_weight_kg' => '80'],
        ]])->assertOk()->assertJsonPath('data.tolerance_status', 'warning');
        $response->assertJsonPath('data.lots.0.tolerance_status', 'warning')->assertJsonPath('data.lots.0.tolerance.source', 'type')->assertJsonPath('data.lots.0.tolerance.pct', '3.00')
            // 20 % short, and only an excess counts for this rule: within, with the signed gap still shown.
            ->assertJsonPath('data.lots.1.tolerance_status', 'within')->assertJsonPath('data.lots.1.tolerance.source', 'type_channel')
            ->assertJsonPath('data.lots.1.tolerance.direction', 'excess')->assertJsonPath('data.lots.1.tolerance.pct', '-20.00')->assertJsonPath('data.lots.1.weight_variance_pct', '-20.00')
            ->assertJsonPath('data.lots.1.planned_channel', 'energy_recovery');

        // The rule is changed afterwards: the decision already taken keeps the rule it was taken with.
        $this->as($this->admin)->putJson("/api/v1/tolerance-rules/{$tight}", ['waste_type' => $cardboard, 'treatment_channel' => 'energy_recovery', 'warning_pct' => '1', 'direction' => 'both', 'basis' => 'operational'])->assertOk();
        $this->as($this->manager)->getJson("/api/v1/pickups/{$pickup}")->assertJsonPath('data.lots.1.tolerance.warning_pct', '10.00')->assertJsonPath('data.lots.1.tolerance_status', 'within');

        $this->as($this->manager)->getJson('/api/v1/pickups/tolerance-summary')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.received', 2)->assertJsonPath('meta.warning', 1)->assertJsonPath('meta.within', 1)
            ->assertJsonPath('data.1.waste_type.code', 'CHUTES')->assertJsonPath('data.1.warning', 1);
        $this->asProvider()->getJson('/api/v1/pickups/tolerance-summary')->assertForbidden();
    }

    public function test_above_the_blocking_limit_the_gap_is_accepted_only_by_someone_authorised(): void
    {
        $scraps = $this->type('CHUTES', 'kg');
        $this->rule(['waste_type' => $scraps, 'warning_pct' => '5', 'blocking_pct' => '10']);
        $lot = $this->lot($scraps, '250');
        $pickup = $this->collected([$lot['ulid']]);
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $lot['lot_number'], 'received_weight_kg' => '220']]])
            ->assertOk()->assertJsonPath('data.tolerance_status', 'blocking')->assertJsonPath('data.lots.0.tolerance.pct', '-12.00')->assertJsonPath('data.lots.0.tolerance.blocking_pct', '10.00');
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/treat", ['lots' => [['lot_number' => $lot['lot_number'], 'channel' => 'recycling']]])->assertOk();

        // Nothing was rejected or altered; the pickup simply cannot be closed until the gap is accepted.
        $this->as($this->manager)->postJson("/api/v1/pickups/{$pickup}/complete")->assertStatus(409)->assertJsonPath('code', 'VARIANCE_NOT_ACKNOWLEDGED');
        $this->as($this->manager)->postJson("/api/v1/pickups/{$pickup}/acknowledge-variance", ['comment' => 'Humidité.'])
            ->assertStatus(409)->assertJsonPath('code', 'VARIANCE_NEEDS_AUTHORIZATION');
        $this->as($this->admin)->postJson("/api/v1/pickups/{$pickup}/acknowledge-variance", [])->assertStatus(422);
        $this->as($this->admin)->postJson("/api/v1/pickups/{$pickup}/acknowledge-variance", ['comment' => 'Pesée de départ faite sur chutes mouillées, constat signé.'])
            ->assertOk()->assertJsonPath('data.variance_decision', 'authorized')->assertJsonPath('data.variance_acknowledged', true);
        $this->as($this->manager)->postJson("/api/v1/pickups/{$pickup}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $audit = DB::table('audit_logs')->where('auditable_type', 'pickups')->where('actor_user_id', $this->admin->id)->where('new_values', 'like', '%authorized%')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('constat', (string) $audit->new_values);
        $this->assertSame(['250.000', '250.000', '220.000'], array_values((array) DB::table('pickup_lots')->first(['declared_weight_kg', 'departure_weight_kg', 'received_weight_kg'])));
    }

    public function test_nothing_to_compare_with_is_said_not_computed(): void
    {
        $cones = $this->type('CONES', 'piece');
        $lot = $this->as($this->manager)->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone(), 'waste_type' => $cones, 'quantity' => '50', 'unit' => 'piece'])->json('data');
        // The loader recorded that nothing was counted on departure.
        $pickup = $this->collected([$lot['ulid']], ['departure_quantity' => '0']);

        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/receive", ['lots' => [['lot_number' => $lot['lot_number'], 'received_quantity' => '48']]])
            ->assertOk()->assertJsonPath('data.tolerance_status', 'no_reference')->assertJsonPath('data.variance_flagged', true)
            ->assertJsonPath('data.lots.0.tolerance.pct', null)->assertJsonPath('data.lots.0.tolerance.reference_quantity', '0.000')
            ->assertJsonPath('data.lots.0.declared_quantity', '50.000');
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function rule(array $rule): string
    {
        return $this->as($this->admin)->postJson('/api/v1/tolerance-rules', $rule + ['direction' => 'both', 'basis' => 'operational'])->assertCreated()->json('data.ulid');
    }

    private function type(string $code, string $unit, string $channel = 'recycling'): string
    {
        return $this->tenant()->runAs($this->industrial, fn () => WasteType::query()->firstOrCreate(['code' => $code], ['name' => $code,
            'waste_family_id' => DB::table('waste_families')->value('id'), 'default_unit_id' => DB::table('units')->where('code', $unit)->value('id'),
            'default_treatment_channel_id' => DB::table('treatment_channels')->where('code', $channel)->value('id'),
            'measurement_method' => WasteType::defaultMethod($unit), 'activated_at' => now()])->ulid);
    }

    /**
     * @return array<string, mixed>
     */
    private function lot(string $type, string $kg): array
    {
        return $this->as($this->manager)->postJson('/api/v1/lots', ['site' => $this->site->ulid, 'zone' => $this->zone(), 'waste_type' => $type, 'net_weight_kg' => $kg])->assertCreated()->json('data');
    }

    /**
     * A pickup requested, confirmed and loaded.
     *
     * @param  list<string>  $lots
     * @param  array<string, string>  $loading
     */
    private function collected(array $lots, array $loading = []): string
    {
        $pickup = $this->as($this->manager)->postJson('/api/v1/pickups', ['site' => $this->site->ulid, 'provider' => $this->provider->ulid,
            'requested_date' => now()->addDay()->toDateString(), 'lots' => $lots])->assertCreated()->json('data.ulid');
        $this->as($this->manager)->postJson("/api/v1/pickups/{$pickup}/submit")->assertOk();
        $this->asProvider()->postJson("/api/v1/pickups/{$pickup}/confirm", ['confirmed_date' => now()->addDay()->toDateString()])->assertOk();
        $this->as($this->manager)->postJson("/api/v1/pickups/{$pickup}/collect", ['lots' => array_map(fn (string $lot) => ['lot' => $lot] + $loading, $lots)])->assertOk();

        return $pickup;
    }

    private function zone(): string
    {
        return (string) DB::table('zones')->where('code', 'AIRE')->value('ulid');
    }

    /**
     * @return array<string, mixed>
     */
    private function companyPayload(): array
    {
        return $this->as($this->admin)->getJson('/api/v1/company')->json('data');
    }

    private function as(User $user): static
    {
        return $this->withToken($this->tokenFor($user, $this->industrial));
    }

    private function asProvider(): static
    {
        return $this->withToken($this->tokenFor($this->providerUser, $this->provider));
    }
}
