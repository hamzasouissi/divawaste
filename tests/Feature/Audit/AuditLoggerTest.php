<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use Database\Factories\CompanyFactory;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    public function test_it_writes_an_audit_row_for_a_business_action(): void
    {
        DB::table('currencies')->insert([
            'code' => 'TND',
            'name' => json_encode(['fr' => 'Dinar tunisien', 'en' => 'Tunisian dinar']),
            'minor_unit' => 3,
            'symbol' => 'DT',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('countries')->insert([
            'iso2' => 'TN',
            'iso3' => 'TUN',
            'name' => json_encode(['fr' => 'Tunisie', 'en' => 'Tunisia']),
            'currency_code' => 'TND',
            'default_locale' => 'fr',
            'default_timezone' => 'Africa/Tunis',
            'phone_prefix' => '+216',
            'tax_id_label' => json_encode(['fr' => 'Identifiant fiscal', 'en' => 'Tax ID']),
            'tax_id_pattern' => null,
            'waste_code_system' => 'EWC',
            'regulatory_authority_name' => 'ANPE',
            'is_signup_enabled' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $company = CompanyFactory::new()->create();
        $user = UserFactory::new()->create();

        app(TenantContext::class)->set($company);

        AuditLogger::record([
            'event' => 'status_changed',
            'auditable_type' => 'waste_lot',
            'auditable_id' => 42,
            'auditable_ulid' => '01M3XTSAGKJVBC3XNSYXC6Q5Z4',
            'company_id' => $company->id,
            'actor_user_id' => $user->id,
            'actor_type' => 'user',
            'source' => 'api',
            'old_values' => ['status' => 'draft'],
            'new_values' => ['status' => 'collected'],
            'request_id' => 'req-123',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'url' => '/api/v1/lots/42',
            'http_method' => 'PATCH',
        ]);

        $record = DB::table('audit_logs')->where('request_id', 'req-123')->first();

        $this->assertNotNull($record);
        $this->assertSame('status_changed', $record->event);
        $this->assertSame($company->id, (int) $record->company_id);
        $this->assertSame($user->id, (int) $record->actor_user_id);
    }
}
