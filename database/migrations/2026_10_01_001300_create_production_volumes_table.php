<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_volumes', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('site_id');
            $table->date('period_month');
            $table->unsignedInteger('pieces_produced');
            $table->enum('source', ['manual', 'import', 'production_orders'])->default('manual');
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('entered_by_user_id')->nullable();

            $table->datetimes();

            $table->unique(['company_id', 'id'], 'uq_pv_company_id_id');
            $table->unique(['company_id', 'site_id', 'period_month'], 'uq_pv_site_month');
            $table->index(['company_id', 'site_id', 'period_month'], 'ix_pv_company_site_period');

            $table->foreign(['company_id', 'site_id'], 'fk_pv_site')
                ->references(['company_id', 'id'])
                ->on('sites')
                ->restrictOnDelete();

            $table->foreign('entered_by_user_id', 'fk_pv_entered_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_volumes');
    }
};
