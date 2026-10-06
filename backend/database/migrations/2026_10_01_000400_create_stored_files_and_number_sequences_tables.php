<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            // NULL = platform file.
            $table->foreignId('company_id')->nullable()->constrained('companies', 'id', 'fk_stored_files_company')->restrictOnDelete();
            $table->string('disk', 30);
            $table->string('path', 500)->charset('ascii')->collation('ascii_bin');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->string('purpose', 40);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users', 'id', 'fk_stored_files_uploaded_by')->restrictOnDelete();
            $table->foreignId('uploaded_by_company_id')->nullable()->constrained('companies', 'id', 'fk_stored_files_uploaded_by_company')->restrictOnDelete();
            $table->enum('scan_status', ['pending', 'clean', 'infected', 'skipped'])->default('pending');
            $table->dateTime('scanned_at')->nullable();
            $table->date('retention_until')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->datetimes();
            $table->dateTime('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users', 'id', 'fk_stored_files_deleted_by')->restrictOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->unique(['disk', 'path'], 'uq_stored_files_disk_path');
            $table->unique(['company_id', 'id'], 'uq_stored_files_company_id_id');
            $table->index(['company_id', 'purpose', 'created_at'], 'ix_stored_files_company_purpose');
            $table->index('retention_until', 'ix_stored_files_retention');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('logo_stored_file_id', 'fk_companies_logo')->references('id')->on('stored_files')->nullOnDelete();
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_number_sequences_company')->restrictOnDelete();
            $table->string('sequence_key', 40);
            $table->string('period_key', 10);
            $table->string('prefix', 20);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->datetimes();

            $table->unique(['company_id', 'sequence_key', 'period_key'], 'uq_number_sequences_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::table('companies', fn (Blueprint $table) => $table->dropForeign('fk_companies_logo'));
        Schema::dropIfExists('stored_files');
    }
};
