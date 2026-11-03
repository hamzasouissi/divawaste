<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three things.
 *
 * A provider is a collector, a transporter, a recycler or a sorter (trieur): the eliminator role is withdrawn. A provider
 * that was only an eliminator among the roles that receive waste becomes a sorter, so that it still receives waste; its
 * administrators can correct its roles on its profile.
 *
 * The roll (rouleau) joins the packaging types.
 *
 * Contracts: what an industrial company agreed with a receiver or a transporter — prices per waste type, fees per pickup
 * and per month, how many pickups and when, for how long. A pickup carries the contract of its receiver and the one of
 * its transporter; the prices of the contract are applied at reception and its fees are added to the pickup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', fn (Blueprint $table) => $table->boolean('is_sorter')->default(false)->after('is_recycler'));
        DB::table('provider_profiles')->where('is_eliminator', true)->where('is_collector', false)->where('is_recycler', false)->update(['is_sorter' => true]);
        DB::statement('ALTER TABLE provider_profiles DROP CONSTRAINT chk_provider_profiles_role');
        Schema::table('provider_profiles', fn (Blueprint $table) => $table->dropColumn('is_eliminator'));
        DB::statement('ALTER TABLE provider_profiles ADD CONSTRAINT chk_provider_profiles_role CHECK (is_collector + is_transporter + is_recycler + is_sorter >= 1)');

        Schema::table('external_partners', fn (Blueprint $table) => $table->boolean('is_sorter')->default(false)->after('is_recycler'));
        DB::table('external_partners')->where('is_eliminator', true)->where('is_recycler', false)->update(['is_sorter' => true]);
        DB::statement('ALTER TABLE external_partners DROP CONSTRAINT chk_external_partners_role');
        Schema::table('external_partners', fn (Blueprint $table) => $table->dropColumn('is_eliminator'));
        DB::statement('ALTER TABLE external_partners ADD CONSTRAINT chk_external_partners_role CHECK (is_transporter + is_recycler + is_sorter >= 1)');

        if (! DB::table('packaging_types')->where('code', 'roll')->exists()) {
            DB::table('packaging_types')->insert(['code' => 'roll', 'name' => json_encode(['fr' => 'Rouleau', 'en' => 'Roll'], JSON_UNESCAPED_UNICODE),
                'sort_order' => (int) DB::table('packaging_types')->max('sort_order') + 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $user = fn (Blueprint $table, string $column, string $fk) => $table->foreignId($column)->nullable()->constrained('users', 'id', $fk)->restrictOnDelete();

        Schema::create('contracts', function (Blueprint $table) use ($user) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->string('contract_number', 32);
            // The contract's own reference, as written on the signed paper.
            $table->string('reference', 100)->nullable();
            $table->string('title', 191);
            // The other party: a provider of the platform, or a partner without an account. Exactly one.
            $table->foreignId('provider_company_id')->nullable()->constrained('companies', 'id', 'fk_contracts_provider')->restrictOnDelete();
            $table->unsignedBigInteger('external_partner_id')->nullable();
            // What the party does under the contract: receives the waste (collects, recycles, sorts it) or carries it.
            $table->enum('party_role', ['receiver', 'transporter']);
            // null = every site of the company.
            $table->unsignedBigInteger('site_id')->nullable();
            $table->enum('status', ['draft', 'active', 'terminated'])->default('draft');
            $table->date('starts_on');
            // null = until terminated.
            $table->date('ends_on')->nullable();
            // How often waste is collected, and how many pickups per period; on_demand = when the company asks.
            $table->enum('frequency', ['on_demand', 'weekly', 'biweekly', 'monthly', 'quarterly'])->default('on_demand');
            $table->unsignedSmallInteger('pickups_per_period')->nullable();
            // Preferred days, ['mon', 'thu'] …
            $table->json('weekdays')->nullable();
            // How many pickups the contract plans in all, when it says.
            $table->unsignedInteger('planned_pickups')->nullable();
            // A fee for each pickup (transport, handling) and one per month, each said who pays it (cost = the company pays).
            $table->decimal('pickup_fee', 15, 3)->nullable();
            $table->enum('pickup_fee_direction', ['revenue', 'cost'])->default('cost');
            $table->decimal('monthly_fee', 15, 3)->nullable();
            $table->enum('monthly_fee_direction', ['revenue', 'cost'])->default('cost');
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->char('currency_code', 3);
            $table->text('notes')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('terminated_at')->nullable();
            $table->string('termination_reason', 500)->nullable();
            $table->dateTime('ending_notified_at')->nullable();
            $user($table, 'created_by_user_id', 'fk_contracts_created_by');
            $table->datetimes();

            $table->foreign('company_id', 'fk_contracts_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'external_partner_id'], 'fk_contracts_partner')->references(['company_id', 'id'])->on('external_partners')->restrictOnDelete();
            $table->foreign(['company_id', 'site_id'], 'fk_contracts_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_contracts_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_contracts_company_id_id');
            $table->unique(['company_id', 'contract_number'], 'uq_contracts_number');
            $table->index(['provider_company_id', 'status'], 'ix_contracts_provider');
        });
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT chk_contracts_party CHECK ((provider_company_id IS NULL) <> (external_partner_id IS NULL))');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT chk_contracts_dates CHECK (ends_on IS NULL OR ends_on >= starts_on)');

        Schema::create('contract_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_id');
            // null = every waste type the contract has no line of its own for.
            $table->unsignedBigInteger('waste_type_id')->nullable();
            $table->enum('pricing_mode', ['per_kg', 'per_tonne', 'flat']);
            $table->decimal('unit_price', 15, 3);
            // revenue = the party pays the company for the waste; cost = the company pays the party.
            $table->enum('direction', ['revenue', 'cost']);
            $table->datetimes();

            $table->foreign(['company_id', 'contract_id'], 'fk_contract_prices_contract')->references(['company_id', 'id'])->on('contracts')->cascadeOnDelete();
            $table->foreign(['company_id', 'waste_type_id'], 'fk_contract_prices_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE contract_prices ADD CONSTRAINT chk_contract_prices_price CHECK (unit_price >= 0)');

        Schema::table('pickups', function (Blueprint $table) {
            // The contract of the receiver and the one of the transporter the pickup was made under.
            $table->unsignedBigInteger('contract_id')->nullable()->after('marketplace_negotiation_id');
            $table->unsignedBigInteger('transport_contract_id')->nullable()->after('contract_id');
            // The fees of those contracts for this pickup, signed for the company (negative = it pays), written at reception.
            $table->decimal('contract_fees_amount', 15, 3)->nullable()->after('transport_contract_id');
            $table->foreign(['company_id', 'contract_id'], 'fk_pickups_contract')->references(['company_id', 'id'])->on('contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'transport_contract_id'], 'fk_pickups_transport_contract')->references(['company_id', 'id'])->on('contracts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->dropForeign('fk_pickups_contract');
            $table->dropForeign('fk_pickups_transport_contract');
            $table->dropIndex('fk_pickups_contract');
            $table->dropIndex('fk_pickups_transport_contract');
            $table->dropColumn(['contract_id', 'transport_contract_id', 'contract_fees_amount']);
        });
        Schema::dropIfExists('contract_prices');
        Schema::dropIfExists('contracts');
        DB::table('packaging_types')->where('code', 'roll')->whereNotExists(fn ($q) => $q->from('waste_lots')->whereColumn('waste_lots.packaging_type_id', 'packaging_types.id'))->delete();
        foreach (['provider_profiles' => 'is_collector + is_transporter + is_recycler', 'external_partners' => 'is_transporter + is_recycler'] as $table => $sum) {
            $constraint = $table === 'provider_profiles' ? 'chk_provider_profiles_role' : 'chk_external_partners_role';
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            Schema::table($table, fn (Blueprint $t) => $t->boolean('is_eliminator')->default(false)->after('is_recycler'));
            DB::table($table)->where('is_sorter', true)->update(['is_eliminator' => true]);
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('is_sorter'));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$sum} + is_eliminator >= 1)");
        }
    }
};
