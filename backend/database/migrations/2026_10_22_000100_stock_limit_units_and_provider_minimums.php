<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stock limit is stated in the unit of the restriction it expresses (kilograms, tonnes, pieces, litres, cubic metres,
 * or packages of one packaging), and a scope may carry one limit per measure. Every existing limit was in kilograms and
 * stays so, with the same figure.
 *
 * The minimum a provider asks for a collection becomes a requirement with a unit, an optional packaging and a choice
 * between warning and refusal. Existing minimums were in kilograms and informative: they stay in kilograms and warn.
 * What was decided for a pickup is kept on the pickup, since the provider may change its rules afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_thresholds', function (Blueprint $table) {
            $table->renameColumn('max_quantity_kg', 'max_quantity');
        });
        Schema::table('stock_thresholds', function (Blueprint $table) {
            // kg, t, piece, l, m3, or 'package' (then the packaging says which packages are counted).
            $table->string('unit_code', 10)->default('kg')->after('max_quantity');
            $table->foreignId('packaging_type_id')->nullable()->after('unit_code')->constrained('packaging_types', 'id', 'fk_stock_thresholds_packaging')->restrictOnDelete();
            // What the limit measures: 'mass', 'count', 'volume' or 'package:<packaging id>'. One limit per scope and measure.
            $table->string('measure_key', 40)->default('mass')->after('packaging_type_id');
            $table->unique(['company_id', 'site_id', 'zone_scope_key', 'waste_type_scope_key', 'measure_key'], 'uq_stock_thresholds_measure');
        });
        Schema::table('stock_thresholds', fn (Blueprint $table) => $table->dropUnique('uq_stock_thresholds_subject'));

        Schema::table('provider_accepted_wastes', function (Blueprint $table) {
            $table->decimal('min_quantity', 12, 3)->nullable()->after('min_quantity_kg');
            $table->string('min_unit_code', 10)->nullable()->after('min_quantity');
            // The packaging a minimum in packages counts.
            $table->foreignId('min_packaging_type_id')->nullable()->after('min_unit_code')->constrained('packaging_types', 'id', 'fk_paw_min_packaging')->restrictOnDelete();
            // The packaging the provider asks this waste to arrive in, when it asks for one.
            $table->foreignId('required_packaging_type_id')->nullable()->after('min_packaging_type_id')->constrained('packaging_types', 'id', 'fk_paw_required_packaging')->restrictOnDelete();
            // false = a collection below the requirement is warned about; true = it is not requested.
            $table->boolean('is_blocking')->default(false)->after('required_packaging_type_id');
        });
        DB::table('provider_accepted_wastes')->where('min_quantity_kg', '>', 0)->update(['min_quantity' => DB::raw('min_quantity_kg'), 'min_unit_code' => 'kg']);

        Schema::table('pickups', function (Blueprint $table) {
            // The requirements of the receiver as they stood when the pickup was requested, the figures and the answer.
            $table->json('provider_requirements')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('pickups', fn (Blueprint $table) => $table->dropColumn('provider_requirements'));
        Schema::table('provider_accepted_wastes', function (Blueprint $table) {
            $table->dropForeign('fk_paw_min_packaging');
            $table->dropForeign('fk_paw_required_packaging');
            $table->dropColumn(['min_quantity', 'min_unit_code', 'min_packaging_type_id', 'required_packaging_type_id', 'is_blocking']);
        });
        // Limits in another unit than a weight have no meaning in the previous model: they are not kept.
        DB::table('stock_thresholds')->where('measure_key', '!=', 'mass')->delete();
        DB::table('stock_thresholds')->where('unit_code', 't')->update(['max_quantity' => DB::raw('max_quantity * 1000')]);
        Schema::table('stock_thresholds', function (Blueprint $table) {
            $table->unique(['company_id', 'site_id', 'zone_scope_key', 'waste_type_scope_key'], 'uq_stock_thresholds_subject');
        });
        Schema::table('stock_thresholds', function (Blueprint $table) {
            $table->dropUnique('uq_stock_thresholds_measure');
            $table->dropForeign('fk_stock_thresholds_packaging');
            $table->dropColumn(['unit_code', 'packaging_type_id', 'measure_key']);
        });
        Schema::table('stock_thresholds', fn (Blueprint $table) => $table->renameColumn('max_quantity', 'max_quantity_kg'));
    }
};
