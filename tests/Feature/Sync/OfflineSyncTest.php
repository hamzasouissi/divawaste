<?php

namespace Tests\Feature\Sync;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\Zone;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * M2-08: work recorded offline is replayed without loss and without duplicate.
 */
class OfflineSyncTest extends TestCase
{
    use InteractsWithTenants;

    private Company $company;

    private Site $site;

    private Zone $zone;

    private WasteType $type;

    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);

        $this->company = $this->makeCompany();
        $this->site = $this->makeSite($this->company, 'NAB');
        $this->zone = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $this->site->id)->firstOrFail());
        $this->type = $this->tenant()->runAs($this->company, fn () => WasteType::query()->create([
            'code' => 'CHUTES', 'name' => 'Chutes de coupe', 'activated_at' => now(),
            'waste_family_id' => DB::table('waste_families')->where('code', 'textile_product')->value('id'),
            'default_unit_id' => DB::table('units')->where('code', 'kg')->value('id'),
        ]));

        $operator = $this->makeMember($this->company, ['workshop_operator' => $this->site]);
        $this->deviceToken = $this->withToken($this->tokenFor($operator, $this->company))->postJson('/api/v1/mobile/devices', [
            'device' => strtoupper((string) Str::ulid()), 'name' => 'Tablette coupe', 'platform' => 'android',
        ])->assertCreated()->json('data.token');
        app('auth')->forgetGuards();
    }

    public function test_bootstrap_gives_the_device_what_it_needs_offline(): void
    {
        $data = $this->withToken($this->deviceToken)->getJson('/api/v1/sync/bootstrap')->assertOk()->json('data');

        $this->assertSame([$this->site->ulid], array_column($data['sites'], 'ulid'));
        $this->assertSame($this->zone->ulid, $data['sites'][0]['zones'][0]['ulid']);
        $this->assertSame([$this->type->ulid], array_column($data['waste_types'], 'ulid'));
    }

    public function test_offline_lots_are_created_once_however_many_times_the_batch_is_replayed(): void
    {
        $first = $this->createOperation(1, '42.500', now()->subHours(3));
        $second = $this->createOperation(2, '18', now()->subHours(2));
        $batch = ['sent_at' => now()->toIso8601String(), 'operations' => [$second, $first]];

        $results = $this->withToken($this->deviceToken)->postJson('/api/v1/sync/push', $batch)->assertOk()->json('data');
        $this->assertSame(['applied', 'applied'], array_column($results, 'status'));
        // Applied in the order the device recorded them, whatever the order in the request.
        $this->assertSame([$first['id'], $second['id']], array_column($results, 'id'));
        $this->assertSame('42.500', $results[0]['result']['net_weight_kg']);

        // The answer was lost: the device sends the same batch again, twice.
        foreach ([1, 2] as $ignored) {
            $replay = $this->withToken($this->deviceToken)->postJson('/api/v1/sync/push', $batch)->assertOk()->json('data');
            $this->assertSame(['applied', 'applied'], array_column($replay, 'status'));
            $this->assertSame([true, true], array_column($replay, 'duplicate'));
            $this->assertSame($results[0]['result']['lot_number'], $replay[0]['result']['lot_number']);
        }
        $this->assertSame(2, DB::table('waste_lots')->where('company_id', $this->company->id)->count());
        $this->assertSame(2, DB::table('sync_operations')->count());

        // The lot keeps the time it was really produced, and is marked as coming from a synchronisation.
        $lot = DB::table('waste_lots')->where('ulid', $first['payload']['ulid'])->first();
        $this->assertEqualsWithDelta(now()->subHours(3)->getTimestamp(), strtotime($lot->generated_at.' UTC'), 5);
        $this->assertDatabaseHas('waste_lot_events', ['waste_lot_id' => $lot->id, 'event_type' => 'created', 'source' => 'sync']);

        // Same lot sent under a new operation id (the app was reinstalled mid-sync): still one lot.
        $again = ['id' => strtoupper((string) Str::ulid()), 'sequence' => 3] + $first;
        $this->withToken($this->deviceToken)->postJson('/api/v1/sync/push', ['operations' => [$again]])->assertOk()->assertJsonPath('data.0.status', 'applied');
        $this->assertSame(2, DB::table('waste_lots')->where('company_id', $this->company->id)->count());

        // An operation id reused for different data is refused, not applied.
        $tampered = $first;
        $tampered['payload']['net_weight_kg'] = '999';
        $this->withToken($this->deviceToken)->postJson('/api/v1/sync/push', ['operations' => [$tampered]])->assertOk()
            ->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.0.error_code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_an_invalid_operation_is_kept_as_rejected_and_does_not_block_the_others(): void
    {
        $bad = $this->createOperation(1, '10');
        $bad['payload']['waste_type'] = strtoupper((string) Str::ulid());
        $good = $this->createOperation(2, '11');

        $results = $this->withToken($this->deviceToken)->postJson('/api/v1/sync/push', ['operations' => [$bad, $good]])->assertOk()->json('data');

        $this->assertSame(['rejected', 'applied'], array_column($results, 'status'));
        $this->assertSame('VALIDATION_FAILED', $results[0]['error_code']);
        $this->assertArrayHasKey('waste_type', $results[0]['result']['errors']);
        $this->assertDatabaseHas('sync_operations', ['client_operation_id' => $bad['id'], 'status' => 'rejected']);
        // Replaying the rejected one gives the same answer instead of trying again forever.
        $this->withToken($this->deviceToken)->postJson('/api/v1/sync/push', ['operations' => [$bad]])->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.0.duplicate', true);
    }

    public function test_storing_a_lot_that_moved_on_meanwhile_is_reported_as_a_conflict(): void
    {
        $create = $this->createOperation(1, '30');
        $this->withToken($this->deviceToken)->postJson('/api/v1/sync/push', ['operations' => [$create]])->assertOk();
        DB::table('waste_lots')->where('ulid', $create['payload']['ulid'])->update(['status' => 'collected']);

        $store = ['id' => strtoupper((string) Str::ulid()), 'sequence' => 2, 'type' => 'lot.store', 'occurred_at' => now()->toIso8601String(),
            'payload' => ['lot' => $create['payload']['ulid']]];
        $this->withToken($this->deviceToken)->postJson('/api/v1/sync/push', ['operations' => [$store]])->assertOk()
            ->assertJsonPath('data.0.status', 'conflict')->assertJsonPath('data.0.conflict_type', 'lot_state_changed')
            ->assertJsonPath('data.0.server_state.status', 'collected');
    }

    public function test_only_a_registered_device_pushes_and_never_into_another_company_or_site(): void
    {
        $operator = $this->makeMember($this->company, ['workshop_operator' => $this->site]);
        $this->withToken($this->tokenFor($operator, $this->company))->postJson('/api/v1/sync/push', ['operations' => [$this->createOperation(1, '5')]])->assertForbidden();

        // A site of the same company where the operator has no role, and a site of another company.
        $otherSite = $this->makeSite($this->company, 'SFX');
        $otherZone = $this->tenant()->runAs($this->company, fn () => Zone::query()->where('site_id', $otherSite->id)->firstOrFail());
        $foreign = $this->makeCompany();
        $foreignSite = $this->makeSite($foreign, 'XXX');
        $foreignZone = $this->tenant()->runAs($foreign, fn () => Zone::query()->where('site_id', $foreignSite->id)->firstOrFail());

        $noRight = $this->createOperation(1, '5');
        $noRight['payload'] = ['site' => $otherSite->ulid, 'zone' => $otherZone->ulid] + $noRight['payload'];
        $crossTenant = $this->createOperation(2, '5');
        $crossTenant['payload'] = ['site' => $foreignSite->ulid, 'zone' => $foreignZone->ulid] + $crossTenant['payload'];

        app('auth')->forgetGuards();
        $results = $this->withToken($this->deviceToken)->postJson('/api/v1/sync/push', ['operations' => [$noRight, $crossTenant]])->assertOk()->json('data');
        $this->assertSame(['FORBIDDEN', 'VALIDATION_FAILED'], array_column($results, 'error_code'));
        $this->assertSame(0, DB::table('waste_lots')->count());
    }

    /**
     * @return array{id: string, sequence: int, type: string, occurred_at: string, payload: array<string, mixed>}
     */
    private function createOperation(int $sequence, string $weight, mixed $occurredAt = null): array
    {
        return [
            'id' => strtoupper((string) Str::ulid()), 'sequence' => $sequence, 'type' => 'lot.create',
            'occurred_at' => ($occurredAt ?? now())->toIso8601String(),
            'payload' => ['ulid' => strtoupper((string) Str::ulid()), 'site' => $this->site->ulid, 'zone' => $this->zone->ulid,
                'waste_type' => $this->type->ulid, 'net_weight_kg' => $weight],
        ];
    }
}
