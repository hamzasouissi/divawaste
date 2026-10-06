<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_reports', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('report_type', 50);
            $table->enum('format', ['pdf', 'xlsx', 'csv']);
            $table->json('parameters');
            $table->enum('status', ['queued', 'processing', 'completed', 'failed', 'expired'])->default('queued');
            $table->unsignedBigInteger('stored_file_id')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();

            $table->datetimes();

            $table->unique(['company_id', 'id'], 'uq_gr_company_id_id');
            $table->unique('ulid', 'uq_gr_ulid');
            $table->index(['company_id', 'requested_by_user_id', 'created_at'], 'ix_gr_company_user');
            $table->index(['status', 'expires_at'], 'ix_gr_status_expires');
            $table->index(['company_id', 'stored_file_id'], 'ix_gr_file');

            $table->foreign('requested_by_user_id', 'fk_gr_requested_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign(['company_id', 'stored_file_id'], 'fk_gr_file')
                ->references(['company_id', 'id'])
                ->on('stored_files')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_reports');
    }
};
