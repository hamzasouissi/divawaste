<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->json('name');
            $table->unsignedTinyInteger('minor_unit');
            $table->string('symbol', 8);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('iso2', 2)->unique('uq_countries_iso2');
            $table->char('iso3', 3)->unique('uq_countries_iso3');
            $table->json('name');
            $table->char('currency_code', 3);
            $table->string('default_locale', 5)->default('fr');
            $table->string('default_timezone', 64);
            $table->string('phone_prefix', 6);
            $table->json('tax_id_label');
            $table->string('tax_id_pattern', 191)->nullable();
            $table->string('waste_code_system', 20);
            $table->string('regulatory_authority_name', 100)->nullable();
            $table->boolean('is_signup_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->foreign('currency_code', 'fk_countries_currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
        Schema::dropIfExists('currencies');
    }
};
