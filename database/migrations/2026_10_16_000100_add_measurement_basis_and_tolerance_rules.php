<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waste is not always weighed: a type is counted in the unit it names (kg, pieces, litres) and a gap between what was
 * declared and what was received is judged in that unit, against a percentage that depends on the type and on what is
 * done with the waste. Nothing existing is rewritten: every lot and pickup line already recorded stays a weighed one.
 */
return new class extends Migration
{
    private const OUTCOMES = ['within', 'warning', 'blocking', 'no_reference', 'not_measured'];

    public function up(): void
    {
        Schema::table('waste_types', function (Blueprint $table) {
            // How the quantity of a lot of this type is obtained. What it is counted in is its unit.
            $table->enum('measurement_method', ['weighed', 'counted', 'measured', 'estimated'])->default('weighed')->after('default_unit_id');
        });

        // Types already counted in pieces or litres say so; their existing lots keep the weight they were given.
        foreach (['count' => 'counted', 'volume' => 'measured'] as $dimension => $method) {
            DB::table('waste_types')->whereIn('default_unit_id', DB::table('units')->where('dimension', $dimension)->pluck('id'))->update(['measurement_method' => $method]);
        }

        Schema::table('waste_lots', function (Blueprint $table) {
            // 'computed' = quantity × the conversion of the type; 'unknown' = counted waste nobody weighed (net_weight_kg is then 0).
            $table->enum('weight_basis', ['measured', 'computed', 'unknown'])->default('measured')->after('net_weight_kg');
        });

        Schema::create('waste_tolerance_rules', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            // NULL = every waste type; NULL channel = whatever is done with the waste.
            $table->unsignedBigInteger('waste_type_id')->nullable();
            $table->foreignId('treatment_channel_id')->nullable()->constrained('treatment_channels', 'id', 'fk_wtr_channel')->restrictOnDelete();
            $table->unsignedBigInteger('waste_type_scope_key')->storedAs('IFNULL(waste_type_id, 0)');
            $table->unsignedBigInteger('channel_scope_key')->storedAs('IFNULL(treatment_channel_id, 0)');
            $table->decimal('warning_pct', 6, 2)->nullable();
            $table->decimal('blocking_pct', 6, 2)->nullable();
            // Which gaps count: both ways, only more than declared, or only less.
            $table->enum('direction', ['both', 'excess', 'shortage'])->default('both');
            // An operating habit of the company, or a requirement it has verified in a text it names.
            $table->enum('basis', ['operational', 'legal'])->default('operational');
            $table->string('reference', 255)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'fk_wtr_created_by')->restrictOnDelete();
            $table->datetimes();

            $table->foreign('company_id', 'fk_wtr_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'waste_type_id'], 'fk_wtr_waste_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_wtr_company_id_id');
            $table->unique(['company_id', 'waste_type_scope_key', 'channel_scope_key'], 'uq_wtr_subject');
        });
        DB::statement('ALTER TABLE waste_tolerance_rules ADD CONSTRAINT chk_wtr_thresholds CHECK ((warning_pct IS NOT NULL OR blocking_pct IS NOT NULL)'
            .' AND (warning_pct IS NULL OR warning_pct > 0) AND (blocking_pct IS NULL OR blocking_pct > 0)'
            .' AND (warning_pct IS NULL OR blocking_pct IS NULL OR blocking_pct >= warning_pct))');
        DB::statement("ALTER TABLE waste_tolerance_rules ADD CONSTRAINT chk_wtr_legal_reference CHECK (basis = 'operational' OR reference IS NOT NULL)");

        Schema::table('pickup_lots', function (Blueprint $table) {
            // What the line is compared in, as the waste type said when the pickup was requested.
            $table->enum('measure_dimension', ['mass', 'count', 'volume'])->default('mass')->after('is_hazardous');
            $table->string('unit_code', 10)->nullable()->after('measure_dimension');
            $table->decimal('declared_quantity', 12, 3)->nullable()->after('declared_weight_kg');
            $table->decimal('departure_quantity', 12, 3)->nullable()->after('departure_weight_kg');
            $table->decimal('received_quantity', 12, 3)->nullable()->after('received_weight_kg');
            $table->decimal('quantity_variance', 12, 3)->nullable()->after('weight_variance_pct');
            $table->enum('tolerance_status', self::OUTCOMES)->nullable()->after('variance_flagged');
            // The rule as it stood when the line was judged: a later change of the settings does not rewrite the decision.
            $table->json('tolerance_rule')->nullable()->after('tolerance_status');
        });

        Schema::table('pickups', function (Blueprint $table) {
            $table->enum('tolerance_status', self::OUTCOMES)->nullable()->after('variance_flagged');
            // 'acknowledged' = explained; 'authorized' = a gap above the blocking limit accepted by someone entitled to.
            $table->enum('variance_decision', ['acknowledged', 'authorized'])->nullable()->after('variance_comment');
        });
        DB::table('pickups')->whereNotNull('variance_acknowledged_at')->update(['variance_decision' => 'acknowledged']);
    }

    public function down(): void
    {
        Schema::table('pickups', fn (Blueprint $table) => $table->dropColumn(['tolerance_status', 'variance_decision']));
        Schema::table('pickup_lots', fn (Blueprint $table) => $table->dropColumn(['measure_dimension', 'unit_code', 'declared_quantity',
            'departure_quantity', 'received_quantity', 'quantity_variance', 'tolerance_status', 'tolerance_rule']));
        Schema::dropIfExists('waste_tolerance_rules');
        Schema::table('waste_lots', fn (Blueprint $table) => $table->dropColumn('weight_basis'));
        Schema::table('waste_types', fn (Blueprint $table) => $table->dropColumn('measurement_method'));
    }
};
