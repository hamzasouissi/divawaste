<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique('uq_subscription_plans_code');
            $table->json('name');
            $table->json('description')->nullable();
            $table->enum('billing_model', ['trial', 'per_site', 'per_volume', 'hybrid']);
            $table->boolean('is_trial')->default(false);
            $table->unsignedSmallInteger('trial_days')->nullable();
            $table->unsignedSmallInteger('max_sites')->nullable();
            $table->unsignedSmallInteger('max_users')->nullable();
            $table->decimal('included_tonnes_per_period', 14, 3)->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->datetimes();
        });

        Schema::create('subscription_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans', 'id', 'fk_spp_plan')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->enum('billing_interval', ['monthly', 'yearly']);
            $table->enum('component', ['base_fee', 'per_site', 'per_tonne']);
            $table->decimal('unit_amount', 15, 3);
            $table->decimal('tier_min', 14, 3)->default(0);
            $table->decimal('tier_max', 14, 3)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->foreign('currency_code', 'fk_spp_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['subscription_plan_id', 'currency_code', 'billing_interval', 'component', 'tier_min', 'valid_from'], 'uq_spp_plan_price');
            $table->index(['subscription_plan_id', 'currency_code', 'billing_interval', 'valid_from'], 'ix_spp_lookup');
        });

        Schema::create('invoice_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('series', 10);
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('prefix', 20);
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->datetimes();

            $table->unique(['series', 'fiscal_year'], 'uq_invoice_number_sequences_series_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
        Schema::dropIfExists('subscription_plan_prices');
        Schema::dropIfExists('subscription_plans');
    }
};
