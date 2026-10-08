<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production reference: just enough to say where a waste comes from — which manufacturing order, order, client,
 * raw material and supplier. No planning, purchasing, stock or prices: those belong to an ERP, which can feed these
 * tables through the import.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fabric is counted in metres.
        DB::statement("ALTER TABLE units MODIFY dimension ENUM('mass', 'volume', 'count', 'length') NOT NULL");
        DB::table('units')->insertOrIgnore(['code' => 'm', 'name' => json_encode(['fr' => 'Mètre', 'en' => 'Metre'], JSON_UNESCAPED_UNICODE), 'symbol' => 'm',
            'dimension' => 'length', 'factor_to_base' => 1, 'is_base' => true, 'created_at' => now(), 'updated_at' => now()]);

        $party = function (string $name, string $short) {
            Schema::create($name, function (Blueprint $table) use ($short) {
                $table->id();
                $table->publicUlid();
                $table->foreignId('company_id')->constrained('companies', 'id', "fk_{$short}_company")->restrictOnDelete();
                $table->string('code', 40);
                $table->string('name', 191);
                $table->char('country_code', 2)->nullable();
                $table->string('contact_name', 150)->nullable();
                $table->string('email', 191)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('notes', 1000)->nullable();
                $table->boolean('is_active')->default(true);
                $table->datetimes();

                $table->unique(['company_id', 'id'], "uq_{$short}_company_id_id");
                $table->unique(['company_id', 'code'], "uq_{$short}_company_code");
            });
        };
        $party('production_clients', 'pcl');
        $party('production_suppliers', 'psu');

        // Stages of production. Like colours: defaults for everyone (company_id NULL), plus the ones a company adds.
        Schema::create('production_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies', 'id', 'fk_production_steps_company')->restrictOnDelete();
            $table->string('code', 40)->unique('uq_production_steps_code');
            $table->json('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });
        $now = now();
        DB::table('production_steps')->insert(array_map(fn (array $step) => [
            'code' => $step[0], 'name' => json_encode(['fr' => $step[1], 'en' => $step[2]], JSON_UNESCAPED_UNICODE), 'sort_order' => $step[3], 'created_at' => $now, 'updated_at' => $now,
        ], [
            ['cutting', 'Coupe', 'Cutting', 10], ['sewing', 'Confection', 'Sewing', 20], ['washing', 'Lavage', 'Washing', 30], ['dyeing', 'Teinture', 'Dyeing', 40],
            ['finishing', 'Finition', 'Finishing', 50], ['quality_control', 'Contrôle qualité', 'Quality control', 60], ['packing', 'Emballage', 'Packing', 70],
        ]));

        Schema::create('raw_materials', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_raw_materials_company')->restrictOnDelete();
            $table->string('reference', 60);
            $table->string('name', 191);
            $table->unsignedBigInteger('production_supplier_id')->nullable();
            // How the material is counted when consumed: metres or kilograms most of the time.
            $table->foreignId('unit_id')->constrained('units', 'id', 'fk_raw_materials_unit')->restrictOnDelete();
            $table->foreignId('color_family_id')->nullable()->constrained('color_families', 'id', 'fk_raw_materials_color')->restrictOnDelete();
            $table->string('color_label', 100)->nullable();
            $table->decimal('grammage_gsm', 7, 2)->nullable();
            // [{material: 'CO', pct: '65.00'}, …] — copied onto the lots of waste that come from it.
            $table->json('composition')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->foreign(['company_id', 'production_supplier_id'], 'fk_raw_materials_supplier')->references(['company_id', 'id'])->on('production_suppliers')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_raw_materials_company_id_id');
            $table->unique(['company_id', 'reference'], 'uq_raw_materials_company_reference');
        });

        // What was received of a material: a waste can then point to the exact delivery.
        Schema::create('raw_material_batches', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('raw_material_id');
            $table->string('batch_number', 60);
            $table->date('received_on')->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('notes', 500)->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'raw_material_id'], 'fk_rmb_material')->references(['company_id', 'id'])->on('raw_materials')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_rmb_company_id_id');
            $table->unique(['raw_material_id', 'batch_number'], 'uq_rmb_material_batch');
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_articles_company')->restrictOnDelete();
            $table->string('reference', 60);
            $table->string('name', 191);
            $table->string('family', 80)->nullable();
            $table->unsignedBigInteger('production_client_id')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->foreign(['company_id', 'production_client_id'], 'fk_articles_client')->references(['company_id', 'id'])->on('production_clients')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_articles_company_id_id');
            $table->unique(['company_id', 'reference'], 'uq_articles_company_reference');
        });

        // What one piece of the article is expected to consume.
        Schema::create('article_materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('raw_material_id');
            $table->decimal('quantity_per_piece', 12, 4);
            $table->datetimes();

            $table->foreign(['company_id', 'article_id'], 'fk_am_article')->references(['company_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->foreign(['company_id', 'raw_material_id'], 'fk_am_material')->references(['company_id', 'id'])->on('raw_materials')->restrictOnDelete();
            $table->unique(['article_id', 'raw_material_id'], 'uq_am_article_material');
        });

        Schema::create('customer_orders', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_customer_orders_company')->restrictOnDelete();
            $table->string('order_number', 60);
            $table->unsignedBigInteger('production_client_id');
            $table->date('ordered_on')->nullable();
            $table->date('due_on')->nullable();
            $table->enum('status', ['open', 'in_progress', 'completed', 'cancelled'])->default('open');
            $table->string('notes', 1000)->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'production_client_id'], 'fk_customer_orders_client')->references(['company_id', 'id'])->on('production_clients')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_customer_orders_company_id_id');
            $table->unique(['company_id', 'order_number'], 'uq_customer_orders_company_number');
        });

        Schema::create('customer_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_order_id');
            $table->unsignedBigInteger('article_id');
            $table->unsignedInteger('quantity');
            $table->datetimes();

            $table->foreign(['company_id', 'customer_order_id'], 'fk_col_order')->references(['company_id', 'id'])->on('customer_orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'article_id'], 'fk_col_article')->references(['company_id', 'id'])->on('articles')->restrictOnDelete();
            $table->unique(['customer_order_id', 'article_id'], 'uq_col_order_article');
        });

        // Ordre de fabrication (OF): an article to make, for an order or not.
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_production_orders_company')->restrictOnDelete();
            $table->string('order_number', 60);
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('customer_order_id')->nullable();
            $table->unsignedInteger('quantity_planned');
            $table->unsignedInteger('quantity_produced')->nullable();
            $table->date('started_on')->nullable();
            $table->date('finished_on')->nullable();
            $table->enum('status', ['planned', 'in_progress', 'completed', 'cancelled'])->default('planned');
            $table->string('notes', 1000)->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'article_id'], 'fk_production_orders_article')->references(['company_id', 'id'])->on('articles')->restrictOnDelete();
            $table->foreign(['company_id', 'customer_order_id'], 'fk_production_orders_order')->references(['company_id', 'id'])->on('customer_orders')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_production_orders_company_id_id');
            $table->unique(['company_id', 'order_number'], 'uq_production_orders_company_number');
            $table->index(['company_id', 'status'], 'ix_production_orders_status');
        });

        // The stages an OF goes through; each may run on a different site (cut here, sewn there).
        Schema::create('production_order_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('production_order_id');
            $table->foreignId('production_step_id')->constrained('production_steps', 'id', 'fk_pos_step')->restrictOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->unsignedInteger('pieces_done')->nullable();
            $table->date('started_on')->nullable();
            $table->date('finished_on')->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'production_order_id'], 'fk_pos_order')->references(['company_id', 'id'])->on('production_orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'site_id'], 'fk_pos_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->unique(['production_order_id', 'production_step_id'], 'uq_pos_order_step');
            $table->index(['company_id', 'site_id', 'production_step_id'], 'ix_pos_site_step');
        });

        // What the OF really consumed, optionally from which delivery.
        Schema::create('production_order_consumptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('production_order_id');
            $table->unsignedBigInteger('raw_material_id');
            $table->unsignedBigInteger('raw_material_batch_id')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->datetimes();

            $table->foreign(['company_id', 'production_order_id'], 'fk_poc_order')->references(['company_id', 'id'])->on('production_orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'raw_material_id'], 'fk_poc_material')->references(['company_id', 'id'])->on('raw_materials')->restrictOnDelete();
            $table->foreign(['company_id', 'raw_material_batch_id'], 'fk_poc_batch')->references(['company_id', 'id'])->on('raw_material_batches')->restrictOnDelete();
            $table->index(['company_id', 'raw_material_id'], 'ix_poc_material');
        });
    }

    public function down(): void
    {
        foreach (['production_order_consumptions', 'production_order_steps', 'production_orders', 'customer_order_lines', 'customer_orders',
            'article_materials', 'articles', 'raw_material_batches', 'raw_materials', 'production_steps', 'production_suppliers', 'production_clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
