<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_sites_company')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->enum('site_kind', ['production', 'warehouse', 'mixed'])->default('production');
            $table->string('address_line1', 191)->nullable();
            $table->string('address_line2', 191)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('region', 100)->nullable();
            $table->foreignId('country_id')->constrained('countries', 'id', 'fk_sites_country')->restrictOnDelete();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('regulatory_identifier', 64)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->decimal('capacity_kg', 14, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('deactivated_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'fk_sites_created_by')->restrictOnDelete();
            $table->datetimes();
            $table->dateTime('deleted_at')->nullable();

            $table->unique(['company_id', 'code'], 'uq_sites_company_code');
            $table->unique(['company_id', 'id'], 'uq_sites_company_id_id');
            $table->index(['company_id', 'is_active'], 'ix_sites_company_active');
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('site_id');
            $table->foreignId('zone_type_id')->constrained('zone_types', 'id', 'fk_zones_zone_type')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->decimal('capacity_kg', 14, 3)->nullable();
            $table->decimal('capacity_m3', 12, 3)->nullable();
            $table->unsignedTinyInteger('capacity_alert_pct')->default(90);
            $table->boolean('is_waste_storage')->default(false);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign(['company_id', 'site_id'], 'fk_zones_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->unique(['company_id', 'site_id', 'code'], 'uq_zones_site_code');
            $table->unique(['company_id', 'id'], 'uq_zones_company_id_id');
            // Target of FKs enforcing "zone belongs to site" (lots, thresholds).
            $table->unique(['company_id', 'site_id', 'id'], 'uq_zones_company_site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
        Schema::dropIfExists('sites');
    }
};
