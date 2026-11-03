<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The charges of a contract, as many as it has (the owner, 2026-10-30: "the paying regime must be more flexible …
 * we can put extra pays"): a name, an amount, when it is due — per pickup, per tonne received, per month, once — and who
 * pays whom. The single fee per pickup and monthly fee of a contract stay as they were; these come beside them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_id');
            $table->string('label', 120);
            $table->decimal('amount', 15, 3);
            // per_pickup = each pickup received; per_tonne = each tonne received; per_month = each month begun; once = one time
            $table->enum('basis', ['per_pickup', 'per_tonne', 'per_month', 'once']);
            // revenue = the party pays the company; cost = the company pays the party.
            $table->enum('direction', ['revenue', 'cost']);
            $table->unsignedSmallInteger('position')->default(0);
            $table->datetimes();

            $table->index(['company_id', 'contract_id'], 'ix_contract_charges_contract');
            $table->foreign(['company_id', 'contract_id'], 'fk_contract_charges_contract')->references(['company_id', 'id'])->on('contracts')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE contract_charges ADD CONSTRAINT chk_contract_charges_amount CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_charges');
    }
};
