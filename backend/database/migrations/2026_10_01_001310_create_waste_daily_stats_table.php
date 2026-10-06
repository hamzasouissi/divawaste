<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_daily_stats', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('site_id');
            $table->date('stat_date');
            $table->unsignedBigInteger('waste_type_id');
            $table->unsignedBigInteger('treatment_channel_id')->nullable();
            $table->unsignedBigInteger('treatment_channel_key')->storedAs('IFNULL(treatment_channel_id, 0)');

            $table->decimal('generated_kg', 16, 3)->default(0);
            $table->unsignedInteger('generated_lot_count')->default(0);
            $table->decimal('disposed_kg', 16, 3)->default(0);
            $table->unsignedInteger('disposed_lot_count')->default(0);
            $table->decimal('net_amount', 15, 3)->default(0);
            $table->dateTime('computed_at');

            $table->datetimes();

            $table->unique(['company_id', 'site_id', 'stat_date', 'waste_type_id', 'treatment_channel_key'], 'uq_wds_grain');
            $table->index(['company_id', 'stat_date'], 'ix_wds_company_date');
            $table->index(['company_id', 'waste_type_id'], 'ix_wds_waste_type');
            $table->index('treatment_channel_id', 'ix_wds_channel');

            $table->foreign(['company_id', 'site_id'], 'fk_wds_site')
                ->references(['company_id', 'id'])
                ->on('sites')
                ->restrictOnDelete();

            $table->foreign(['company_id', 'waste_type_id'], 'fk_wds_waste_type')
                ->references(['company_id', 'id'])
                ->on('waste_types')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_daily_stats');
    }
};
