<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production becomes traceable in money as well as in material. Three kinds of lot stay apart:
 *  - a RECEIVED lot (raw_material_batches) is a delivery of a raw material by a supplier, with what it cost;
 *  - a PRODUCED lot (produced_lots) is finished goods made by an OF for its client: it has no supplier;
 *  - a WASTE lot (waste_lots) is unchanged.
 * Nothing here is a stock: received and produced lots have no balance, no location and no movement.
 * Amounts are excluding tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->string('category', 80)->nullable()->after('name');
        });

        Schema::table('raw_material_batches', function (Blueprint $table) {
            // Who delivered THIS lot. The supplier of the material is only the one proposed by default.
            $table->unsignedBigInteger('production_supplier_id')->nullable()->after('raw_material_id');
            $table->string('supplier_batch_reference', 80)->nullable()->after('batch_number');
            $table->decimal('unit_price', 14, 4)->nullable()->after('quantity');
            $table->decimal('total_amount', 14, 3)->nullable()->after('unit_price');
            // Transport and other costs of bringing this lot in; spread over its quantity.
            $table->decimal('delivery_cost', 14, 3)->nullable()->after('total_amount');
            $table->char('currency_code', 3)->nullable()->after('delivery_cost');
            $table->decimal('tax_rate_pct', 5, 2)->nullable()->after('currency_code');
            $table->string('delivery_note', 80)->nullable()->after('tax_rate_pct');
            $table->string('invoice_reference', 80)->nullable()->after('delivery_note');
            $table->string('external_reference', 100)->nullable()->after('invoice_reference');
            $table->string('import_source', 60)->nullable()->after('external_reference');

            $table->foreign(['company_id', 'production_supplier_id'], 'fk_rmb_supplier')->references(['company_id', 'id'])->on('production_suppliers')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_rmb_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['company_id', 'production_supplier_id'], 'ix_rmb_supplier');
            $table->index(['company_id', 'received_on'], 'ix_rmb_received');
        });
        // Lots received so far were delivered, as far as anyone recorded, by the supplier of their material: written on
        // each lot now, so that changing the supplier of a material later leaves them as they are.
        DB::statement('UPDATE raw_material_batches b JOIN raw_materials m ON m.id = b.raw_material_id SET b.production_supplier_id = m.production_supplier_id WHERE b.production_supplier_id IS NULL');

        Schema::table('production_order_consumptions', function (Blueprint $table) {
            $table->foreignId('production_step_id')->nullable()->after('raw_material_batch_id')->constrained('production_steps', 'id', 'fk_poc_step')->restrictOnDelete();
            $table->unsignedBigInteger('site_id')->nullable()->after('production_step_id');
            $table->date('consumed_on')->nullable()->after('quantity');
            $table->string('notes', 500)->nullable()->after('consumed_on');

            $table->foreign(['company_id', 'site_id'], 'fk_poc_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
        });

        // Finished goods made by an OF. The client and the order are those of the OF: they are not copied here.
        Schema::create('produced_lots', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_produced_lots_company')->restrictOnDelete();
            $table->unsignedBigInteger('production_order_id');
            $table->string('lot_number', 60);
            $table->unsignedInteger('quantity');
            $table->foreignId('unit_id')->constrained('units', 'id', 'fk_produced_lots_unit')->restrictOnDelete();
            $table->date('produced_on')->nullable();
            // When the lot was made over several days.
            $table->date('produced_until')->nullable();
            $table->foreignId('production_step_id')->nullable()->constrained('production_steps', 'id', 'fk_produced_lots_step')->restrictOnDelete();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->enum('status', ['produced', 'delivered', 'cancelled'])->default('produced');
            $table->date('delivered_on')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->string('import_source', 60)->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'production_order_id'], 'fk_produced_lots_order')->references(['company_id', 'id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['company_id', 'site_id'], 'fk_produced_lots_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_produced_lots_company_id_id');
            $table->unique(['company_id', 'lot_number'], 'uq_produced_lots_company_number');
            $table->index(['company_id', 'production_order_id'], 'ix_produced_lots_order');
            $table->index(['company_id', 'produced_on'], 'ix_produced_lots_produced');
        });

        // What an OF cost beside its materials: subcontracting, a service, a purchase, transport, labour when it is known.
        Schema::create('production_order_expenses', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_poe_company')->restrictOnDelete();
            $table->unsignedBigInteger('production_order_id');
            $table->enum('category', ['subcontracting', 'external_service', 'purchase', 'transport', 'labour', 'other']);
            $table->string('description', 191);
            $table->decimal('amount', 14, 3);
            $table->char('currency_code', 3);
            $table->date('incurred_on')->nullable();
            // Who was paid, when it is one of the suppliers.
            $table->unsignedBigInteger('production_supplier_id')->nullable();
            // The invoice, quote or receipt that supports it.
            $table->string('reference', 80)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('import_source', 60)->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'production_order_id'], 'fk_poe_order')->references(['company_id', 'id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['company_id', 'production_supplier_id'], 'fk_poe_supplier')->references(['company_id', 'id'])->on('production_suppliers')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_poe_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_poe_company_id_id');
            $table->index(['company_id', 'production_order_id'], 'ix_poe_order');
        });

        // What the client agreed to pay, per article of the order.
        Schema::table('customer_order_lines', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 4)->nullable()->after('quantity');
        });
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->char('currency_code', 3)->nullable()->after('status');
            // A discount on the whole order, as an amount.
            $table->decimal('discount_amount', 14, 3)->nullable()->after('currency_code');
            $table->foreign('currency_code', 'fk_customer_orders_currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        // The invoices and credit notes issued for an order. They are issued elsewhere (accounting software): only their
        // number, date and amount are recorded, so that what was really invoiced can be set against the order.
        Schema::create('customer_order_invoices', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_coi_company')->restrictOnDelete();
            $table->unsignedBigInteger('customer_order_id');
            // When the invoice is for one OF of the order; otherwise it is for the order as a whole.
            $table->unsignedBigInteger('production_order_id')->nullable();
            $table->enum('kind', ['invoice', 'credit_note'])->default('invoice');
            $table->string('number', 60);
            $table->date('issued_on')->nullable();
            // Always positive; a credit note is subtracted.
            $table->decimal('amount', 14, 3);
            $table->char('currency_code', 3);
            $table->string('notes', 500)->nullable();
            $table->string('import_source', 60)->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'customer_order_id'], 'fk_coi_order')->references(['company_id', 'id'])->on('customer_orders')->restrictOnDelete();
            $table->foreign(['company_id', 'production_order_id'], 'fk_coi_production_order')->references(['company_id', 'id'])->on('production_orders')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_coi_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_coi_company_id_id');
            $table->unique(['company_id', 'number'], 'uq_coi_company_number');
            $table->index(['company_id', 'customer_order_id'], 'ix_coi_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_order_invoices');
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->dropForeign('fk_customer_orders_currency');
            $table->dropColumn(['currency_code', 'discount_amount']);
        });
        Schema::table('customer_order_lines', fn (Blueprint $table) => $table->dropColumn('unit_price'));
        Schema::dropIfExists('production_order_expenses');
        Schema::dropIfExists('produced_lots');
        Schema::table('production_order_consumptions', function (Blueprint $table) {
            $table->dropForeign('fk_poc_step');
            $table->dropForeign('fk_poc_site');
            $table->dropColumn(['production_step_id', 'site_id', 'consumed_on', 'notes']);
        });
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->dropForeign('fk_rmb_supplier');
            $table->dropForeign('fk_rmb_currency');
            $table->dropIndex('ix_rmb_supplier');
            $table->dropIndex('ix_rmb_received');
            $table->dropColumn(['production_supplier_id', 'supplier_batch_reference', 'unit_price', 'total_amount', 'delivery_cost', 'currency_code', 'tax_rate_pct', 'delivery_note',
                'invoice_reference', 'external_reference', 'import_source']);
        });
        Schema::table('raw_materials', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
