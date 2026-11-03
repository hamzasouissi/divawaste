<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * R-03: reports generated every month for whoever asked, on the previous month (reports:scheduled, 1st of the month).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('report_type', 50);
            $table->enum('format', ['pdf', 'xlsx']);
            $table->string('lang', 5)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('last_run_on')->nullable();
            $table->datetimes();

            $table->unique('ulid', 'uq_rs_ulid');
            $table->unique(['company_id', 'user_id', 'report_type', 'format'], 'uq_rs_one_per_user');
            $table->foreign('company_id', 'fk_rs_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('user_id', 'fk_rs_user')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
