<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_cs_company')->restrictOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans', 'id', 'fk_cs_plan')->restrictOnDelete();
            $table->enum('status', ['trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired']);
            $table->enum('billing_interval', ['monthly', 'yearly'])->default('monthly');
            $table->enum('payment_method', ['bank_transfer', 'card'])->default('bank_transfer');
            $table->char('currency_code', 3);
            $table->unsignedSmallInteger('sites_quantity')->default(1);
            $table->dateTime('trial_starts_at')->nullable();
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->dateTime('cancellation_requested_at')->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('replaced_by_subscription_id')->nullable();
            // 1 for the current row, NULL otherwise: exactly one current subscription per company.
            $table->boolean('is_current')->nullable();
            $table->json('price_snapshot');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users', 'id', 'fk_cs_created_by')->restrictOnDelete();
            $table->datetimes();

            $table->foreign('currency_code', 'fk_cs_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_cs_company_id_id');
            $table->unique(['company_id', 'is_current'], 'uq_cs_current');
            $table->index(['status', 'current_period_end'], 'ix_cs_status_period_end');
            $table->index(['status', 'trial_ends_at'], 'ix_cs_status_trial_end');
            $table->foreign(['company_id', 'replaced_by_subscription_id'], 'fk_cs_replaced_by')->references(['company_id', 'id'])->on('company_subscriptions')->restrictOnDelete();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_invoices_company')->restrictOnDelete();
            $table->unsignedBigInteger('company_subscription_id')->nullable();
            $table->enum('invoice_type', ['invoice', 'credit_note'])->default('invoice');
            $table->unsignedBigInteger('credited_invoice_id')->nullable();
            $table->string('invoice_number', 30)->nullable()->unique('uq_invoices_number');
            $table->enum('status', ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'cancelled'])->default('draft');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('subtotal_amount', 15, 3)->default(0);
            $table->decimal('tax_amount', 15, 3)->default(0);
            $table->decimal('stamp_duty_amount', 15, 3)->default(0);
            $table->decimal('total_amount', 15, 3)->default(0);
            $table->decimal('amount_paid', 15, 3)->default(0);
            $table->json('seller_snapshot')->nullable();
            $table->json('buyer_snapshot')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->unsignedBigInteger('pdf_stored_file_id')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users', 'id', 'fk_invoices_issued_by')->restrictOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id'], 'uq_invoices_company_id_id');
            $table->foreign('currency_code', 'fk_invoices_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign(['company_id', 'company_subscription_id'], 'fk_invoices_subscription')->references(['company_id', 'id'])->on('company_subscriptions')->restrictOnDelete();
            $table->foreign(['company_id', 'credited_invoice_id'], 'fk_invoices_credited')->references(['company_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['company_id', 'pdf_stored_file_id'], 'fk_invoices_pdf')->references(['company_id', 'id'])->on('stored_files')->restrictOnDelete();
            $table->index(['company_id', 'status', 'due_date'], 'ix_invoices_company_status_due');
            $table->index(['status', 'due_date'], 'ix_invoices_status_due');
            $table->index(['company_id', 'issue_date'], 'ix_invoices_company_issue');
        });
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT chk_invoices_credit_note CHECK (invoice_type = 'invoice' OR credited_invoice_id IS NOT NULL)");

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('invoice_id');
            $table->enum('item_type', ['subscription', 'site', 'volume', 'adjustment', 'discount']);
            $table->string('description', 255);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_amount', 15, 3);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates', 'id', 'fk_invoice_items_tax_rate')->restrictOnDelete();
            $table->decimal('tax_rate_pct', 6, 3)->default(0);
            $table->decimal('line_subtotal', 15, 3);
            $table->decimal('tax_amount', 15, 3);
            $table->decimal('line_total', 15, 3);
            $table->foreignId('subscription_plan_price_id')->nullable()->constrained('subscription_plan_prices', 'id', 'fk_invoice_items_plan_price')->restrictOnDelete();
            // Simplified MVP: usage metering stored on the line (replaces subscription_usage_records).
            $table->enum('usage_metric', ['active_sites', 'tonnes_managed'])->nullable();
            $table->json('usage_details')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->datetimes();

            $table->foreign(['company_id', 'invoice_id'], 'fk_invoice_items_invoice')->references(['company_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->index(['company_id', 'invoice_id', 'sort_order'], 'ix_invoice_items_invoice');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('invoice_id');
            $table->enum('method', ['bank_transfer', 'card']);
            $table->enum('status', ['pending', 'validated', 'rejected', 'refunded'])->default('pending');
            $table->decimal('amount', 15, 3);
            $table->char('currency_code', 3);
            $table->string('bank_reference', 100)->nullable();
            $table->date('declared_paid_on')->nullable();
            $table->unsignedBigInteger('proof_stored_file_id')->nullable();
            $table->foreignId('declared_by_user_id')->nullable()->constrained('users', 'id', 'fk_payments_declared_by')->restrictOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users', 'id', 'fk_payments_validated_by')->restrictOnDelete();
            $table->string('rejection_reason', 500)->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('provider_payment_id', 100)->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'invoice_id'], 'fk_payments_invoice')->references(['company_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_payments_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign(['company_id', 'proof_stored_file_id'], 'fk_payments_proof')->references(['company_id', 'id'])->on('stored_files')->restrictOnDelete();
            $table->unique(['provider', 'provider_payment_id'], 'uq_payments_provider_ref');
            $table->index(['company_id', 'invoice_id'], 'ix_payments_company_invoice');
            $table->index(['status', 'created_at'], 'ix_payments_status_created');
        });
    }

    public function down(): void
    {
        foreach (['payments', 'invoice_items', 'invoices', 'company_subscriptions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
