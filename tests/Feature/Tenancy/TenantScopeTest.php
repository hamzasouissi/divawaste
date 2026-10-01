<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Exceptions\CrossTenantWriteException;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use App\Modules\Tenancy\TenantContext;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->context = app(TenantContext::class);
        $this->a = Company::factory()->active()->create();
        $this->b = Company::factory()->active()->create();

        foreach ([$this->a, $this->b] as $company) {
            $this->context->runAs($company, fn () => CompanyUser::query()->create([
                'user_id' => User::factory()->create()->id,
                'joined_at' => now(),
            ]));
        }
    }

    public function test_queries_only_see_the_current_tenant(): void
    {
        $bMembership = $this->context->runAs($this->b, fn () => CompanyUser::query()->value('id'));

        $this->context->runAs($this->a, function () use ($bMembership) {
            $this->assertSame(1, CompanyUser::query()->count());
            $this->assertNull(CompanyUser::query()->find($bMembership));
        });
    }

    public function test_querying_without_context_fails_closed(): void
    {
        $this->expectException(MissingTenantContextException::class);

        CompanyUser::query()->count();
    }

    public function test_create_stamps_the_current_company(): void
    {
        $membership = $this->context->runAs($this->a, fn () => CompanyUser::query()->create([
            'user_id' => User::factory()->create()->id,
            'joined_at' => now(),
        ]));

        $this->assertSame($this->a->id, $membership->company_id);
    }

    public function test_writing_into_another_tenant_is_rejected(): void
    {
        $this->expectException(CrossTenantWriteException::class);

        $this->context->runAs($this->a, fn () => CompanyUser::query()->create([
            'company_id' => $this->b->id,
            'user_id' => User::factory()->create()->id,
            'joined_at' => now(),
        ]));
    }

    public function test_company_id_can_never_change(): void
    {
        $this->expectException(CrossTenantWriteException::class);

        $this->context->runAs($this->a, function () {
            $membership = CompanyUser::query()->firstOrFail();
            $membership->company_id = $this->b->id;
            $membership->save();
        });
    }

    public function test_system_mode_is_explicit_and_unfiltered(): void
    {
        $this->assertSame(2, $this->context->runAsSystem('test', fn () => CompanyUser::query()->count()));
        $this->assertFalse($this->context->isSystem(), 'system mode must not leak after the callback');
    }
}
