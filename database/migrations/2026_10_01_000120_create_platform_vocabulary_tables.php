<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('textile_activities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique('uq_textile_activities_code');
            $table->json('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->enum('document_type', ['terms', 'privacy', 'dpa', 'provider_terms']);
            $table->string('version', 20);
            $table->string('locale', 5);
            $table->string('title', 191);
            $table->mediumText('content_html');
            $table->char('content_sha256', 64);
            $table->dateTime('published_at')->nullable();
            $table->boolean('requires_reacceptance')->default(true);
            $table->datetimes();

            $table->unique(['document_type', 'version', 'locale'], 'uq_legal_documents_type_version_locale');
            $table->index(['document_type', 'locale', 'published_at'], 'ix_legal_documents_type_published');
        });

        Schema::create('zone_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique('uq_zone_types_code');
            $table->json('name');
            $table->boolean('is_waste_storage')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('packaging_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique('uq_packaging_types_code');
            $table->json('name');
            $table->decimal('default_tare_kg', 12, 3)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique('uq_units_code');
            $table->json('name');
            $table->string('symbol', 10);
            $table->enum('dimension', ['mass', 'volume', 'count']);
            $table->decimal('factor_to_base', 18, 9);
            $table->boolean('is_base')->default(false);
            $table->datetimes();
        });
    }

    public function down(): void
    {
        foreach (['units', 'packaging_types', 'zone_types', 'legal_documents', 'textile_activities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
