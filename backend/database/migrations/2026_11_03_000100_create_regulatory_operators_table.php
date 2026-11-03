<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The official lists of waste operators (ANGed in Tunisia): one row per entry of a list, as the agency publishes it.
 * Read by every company to find a collector, a transporter or a recycler and fill in a partner from it; never edited
 * by hand (`php artisan registry:import-anged` replaces a registry's rows by the file's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulatory_operators', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->string('registry', 20);                       // anged
            $table->char('fingerprint', 40);                      // the entry's identity in its registry (extractor)
            $table->string('list_code', 40);                      // batteries, textile, plastiques…
            $table->string('list_title', 191);
            // Hazardous waste: an authorisation of the ministry; otherwise the specifications ("cahier des charges").
            $table->boolean('hazardous')->default(false);
            $table->string('legal_name', 191);
            $table->string('contact_name', 191)->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('fax', 100)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('head_office', 255)->nullable();
            $table->string('plant_address', 255)->nullable();
            $table->string('governorate', 40)->nullable();
            $table->string('activity', 255)->nullable();           // as printed: "Collecte-Transport et Recyclage-Valorisation"
            $table->text('waste')->nullable();
            $table->boolean('is_collector')->default(false);
            $table->boolean('is_transporter')->default(false);
            $table->boolean('is_recycler')->default(false);        // recycling, recovery, treatment, disposal
            $table->boolean('is_sorter')->default(false);
            $table->boolean('is_storer')->default(false);
            $table->string('authorization_number', 60)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('annual_tonnage', 255)->nullable();     // tonnes a year, as printed (some lists give litres)
            $table->text('collection_area')->nullable();
            $table->string('withdrawal', 255)->nullable();         // the list's "décision de retrait" column
            $table->string('source_file', 191)->nullable();
            $table->string('source_url', 255)->nullable();
            $table->date('extracted_on')->nullable();
            $table->datetimes();

            $table->unique(['registry', 'fingerprint'], 'uq_regulatory_operators_entry');
            $table->index(['registry', 'list_code'], 'ix_regulatory_operators_list');
            $table->index(['governorate'], 'ix_regulatory_operators_governorate');
            $table->index(['legal_name'], 'ix_regulatory_operators_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulatory_operators');
    }
};
