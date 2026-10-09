<?php

use App\Modules\Catalog\Support\Colors;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('color_families', function (Blueprint $table) {
            // "custom" (a name the company or the platform gave) or the colour library the row refers to.
            $table->string('color_system', 20)->default('custom')->after('code');
            // Inside a library: coated, uncoated, metallic… The same reference exists in several collections.
            $table->string('collection', 30)->nullable()->after('color_system');
            // The reference exactly as the library writes it, or the company's own code for a custom colour.
            $table->string('reference_code', 60)->nullable()->after('collection');
            $table->unsignedTinyInteger('rgb_r')->nullable()->after('hex_color');
            $table->unsignedTinyInteger('rgb_g')->nullable()->after('rgb_r');
            $table->unsignedTinyInteger('rgb_b')->nullable()->after('rgb_g');
            // Computed from the screen colour (sRGB, D65), not measured on a sample.
            $table->decimal('lab_l', 7, 3)->nullable()->after('rgb_b');
            $table->decimal('lab_a', 7, 3)->nullable()->after('lab_l');
            $table->decimal('lab_b', 7, 3)->nullable()->after('lab_a');
            // platform (seeded default), import (reference catalogue) or user (typed by a company).
            $table->string('source', 20)->default('user')->after('pantone_reference');
            $table->string('source_name', 120)->nullable()->after('source');
            $table->string('verification_status', 20)->default('unverified')->after('source_name');
            // The catalogue row a company colour was taken from. The colour survives the catalogue.
            $table->foreignId('catalog_color_id')->nullable()->after('verification_status')->constrained('color_families', 'id', 'fk_color_families_catalog')->nullOnDelete();
            // NULL never collides in a unique index: the platform is owner 0.
            $table->unsignedBigInteger('owner_key')->storedAs('COALESCE(company_id, 0)');

            // One row per reference and collection for each owner; the reference alone is not unique.
            $table->unique(['owner_key', 'color_system', 'collection', 'reference_code'], 'uq_color_families_reference');
            $table->index(['source', 'collection', 'id'], 'ix_color_families_catalogue');
        });

        // Existing rows keep their code, name and tint; they gain the values that follow from them.
        Colors::completeDerived();
    }

    public function down(): void
    {
        Schema::table('color_families', function (Blueprint $table) {
            $table->dropForeign('fk_color_families_catalog');
            $table->dropUnique('uq_color_families_reference');
            $table->dropIndex('ix_color_families_catalogue');
            $table->dropColumn(['owner_key', 'catalog_color_id', 'verification_status', 'source_name', 'source', 'lab_b', 'lab_a', 'lab_l', 'rgb_b', 'rgb_g', 'rgb_r', 'reference_code', 'collection', 'color_system']);
        });
    }
};
