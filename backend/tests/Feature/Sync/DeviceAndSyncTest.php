<?php

namespace Tests\Feature\Sync;

use App\Modules\Sync\Models\Device;
use App\Modules\Sync\Models\SyncOperation;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class DeviceAndSyncTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private const DEVICE = '01JC2Z8M4V7H3K9P2RQW5X6Y8Z';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, AuthorizationSeeder::class]);
        $this->company = $this->makeCompany();
    }

    public function test_operator_registers_device_and_gets_a_company_and_device_bound_token(): void
    {
        $operator = $this->makeMember($this->company, ['workshop_operator' => $this->makeSite($this->company, 'SFX')]);

        $token = $this->withToken($this->tokenFor($operator, $this->company))
            ->postJson('/api/v1/mobile/devices', ['device' => strtolower(self::DEVICE), 'name' => 'Tablette coupe', 'platform' => 'android'])
            ->assertCreated()->assertJsonPath('data.device', self::DEVICE)->json('data.token');

        $row = DB::table('personal_access_tokens')->where('name', 'device:'.self::DEVICE)->first(['company_id', 'device_id', 'expires_at', 'abilities']);
        $this->assertSame($this->company->id, $row->company_id);
        $this->assertSame(DB::table('devices')->where('ulid', self::DEVICE)->value('id'), $row->device_id);
        $this->assertNotNull($row->expires_at);
        $this->assertSame('["mobile:*"]', $row->abilities);

        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.ulid', $this->company->ulid);
    }

    public function test_device_registration_requires_mobile_access_and_unused_identifier(): void
    {
        $auditor = $this->makeMember($this->company, ['auditor' => null]);
        $this->withToken($this->tokenFor($auditor, $this->company))
            ->postJson('/api/v1/mobile/devices', ['device' => self::DEVICE, 'name' => 'X'])->assertForbidden();

        $other = $this->makeCompany();
        $otherOperator = $this->makeMember($other, ['workshop_operator' => null]);
        $this->withToken($this->tokenFor($otherOperator, $other))
            ->postJson('/api/v1/mobile/devices', ['device' => self::DEVICE, 'name' => 'B'])->assertCreated();

        $operator = $this->makeMember($this->company, ['workshop_operator' => null]);
        $this->withToken($this->tokenFor($operator, $this->company))
            ->postJson('/api/v1/mobile/devices', ['device' => self::DEVICE, 'name' => 'A'])
            ->assertStatus(409)->assertJsonPath('code', 'DEVICE_ID_TAKEN');
    }

    public function test_sync_operations_are_idempotent_and_tenant_scoped(): void
    {
        $operator = $this->makeMember($this->company, ['workshop_operator' => null]);
        $op = fn () => $this->tenant()->runAs($this->company, function () use ($operator) {
            $device = Device::query()->firstOrCreate(['ulid' => self::DEVICE], ['registered_by_user_id' => $operator->id, 'name' => 'T']);
            $payload = ['site' => 'X', 'net_weight_kg' => '184.500'];

            return SyncOperation::query()->create([
                'device_id' => $device->id, 'user_id' => $operator->id, 'client_operation_id' => '01JC2Z8M4W3V9R6T1QXK5B7N2D',
                'client_sequence' => 1842, 'operation_type' => 'lot.create', 'payload' => $payload,
                'payload_hash' => SyncOperation::hashPayload($payload), 'client_occurred_at' => now(), 'adjusted_occurred_at' => now(),
                'received_at' => now(), 'processed_at' => now(), 'status' => 'applied', 'result' => ['lot_number' => 'L-2026-000001'],
            ]);
        });

        $first = $op();
        $this->assertSame('L-2026-000001', $first->result['lot_number']);
        $this->assertSame(0, $this->tenant()->runAs($this->makeCompany(), fn () => SyncOperation::query()->count()));

        $this->expectException(QueryException::class);
        $op();
    }

    public function test_deleting_a_device_revokes_its_tokens(): void
    {
        $operator = $this->makeMember($this->company, ['workshop_operator' => null]);
        $this->withToken($this->tokenFor($operator, $this->company))
            ->postJson('/api/v1/mobile/devices', ['device' => self::DEVICE, 'name' => 'T'])->assertCreated();

        DB::table('devices')->where('ulid', self::DEVICE)->delete();

        $this->assertSame(0, DB::table('personal_access_tokens')->whereNotNull('device_id')->count());
    }
}
