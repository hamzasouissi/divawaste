<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries', 'id', 'fk_tax_rates_country')->restrictOnDelete();
            $table->enum('tax_type', ['vat', 'stamp_duty', 'withholding']);
            $table->string('code', 30);
            $table->json('name');
            $table->decimal('rate_pct', 6, 3)->nullable();
            $table->decimal('fixed_amount', 15, 3)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('applies_to', 30)->default('subscription');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->foreign('currency_code', 'fk_tax_rates_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['code', 'valid_from'], 'uq_tax_rates_code_valid_from');
            $table->index(['country_id', 'tax_type', 'valid_from'], 'ix_tax_rates_country_type');
        });

        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT chk_tax_rates_value CHECK (rate_pct IS NOT NULL OR fixed_amount IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
