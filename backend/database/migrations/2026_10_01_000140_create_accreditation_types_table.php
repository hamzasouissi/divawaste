<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries', 'id', 'fk_accreditation_types_country')->restrictOnDelete();
            $table->string('code', 40);
            $table->json('name');
            // Simplified MVP eligibility scope (replaces provider_accreditation_scopes).
            $table->boolean('covers_hazardous')->default(false);
            $table->json('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['country_id', 'code'], 'uq_accreditation_types_country_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_types');
    }
};
