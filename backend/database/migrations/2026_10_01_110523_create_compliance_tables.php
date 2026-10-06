<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * ================================================================
         * 1. DOCUMENTS
         * ================================================================
         */
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();

            $table->unsignedBigInteger('company_id');

            $table->string('document_type', 80);
            $table->string('title', 255);
            $table->string('reference_number', 100)->nullable();
            $table->string('status', 40);

            $table->boolean('is_regulatory')->default(false);

            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('pickup_id')->nullable();

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->unsignedBigInteger('shared_with_company_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_company_id')->nullable();

            $table->unsignedBigInteger('stored_file_id')->nullable();

            $table->char('checksum_sha256', 64)->nullable();
            $table->json('source_snapshot')->nullable();

            $table->dateTime('issued_at')->nullable();

            $table->unsignedBigInteger('superseded_by_document_id')->nullable();

            $table->dateTime('retention_until')->nullable();
            $table->boolean('legal_hold')->default(false);

            $table->dateTime('deleted_at')->nullable();
            $table->text('deletion_reason')->nullable();

            $table->datetimes();

            /*
             * Required for composite tenant foreign keys.
             */
            $table->unique(
                ['company_id', 'id'],
                'uq_documents_company_id'
            );

            $table->index(
                ['company_id', 'status'],
                'ix_documents_company_status'
            );

            $table->index(
                ['company_id', 'document_type'],
                'ix_documents_company_type'
            );

            $table->index(
                ['company_id', 'site_id'],
                'ix_documents_company_site'
            );

            $table->index(
                ['company_id', 'pickup_id'],
                'ix_documents_company_pickup'
            );

            $table->index(
                ['company_id', 'stored_file_id'],
                'ix_documents_company_file'
            );

            $table->index(
                ['company_id', 'superseded_by_document_id'],
                'ix_documents_company_superseded'
            );

            $table->foreign(
                'company_id',
                'fk_documents_company'
            )
                ->references('id')
                ->on('companies')
                ->restrictOnDelete();

            $table->foreign(
                ['company_id', 'site_id'],
                'fk_documents_site'
            )
                ->references(['company_id', 'id'])
                ->on('sites')
                ->restrictOnDelete();

            $table->foreign(
                ['company_id', 'pickup_id'],
                'fk_documents_pickup'
            )
                ->references(['company_id', 'id'])
                ->on('pickups')
                ->restrictOnDelete();

            $table->foreign(
                'shared_with_company_id',
                'fk_documents_shared_company'
            )
                ->references('id')
                ->on('companies')
                ->nullOnDelete();

            $table->foreign(
                'uploaded_by_company_id',
                'fk_documents_uploader_company'
            )
                ->references('id')
                ->on('companies')
                ->nullOnDelete();

            /*
             * stored_files is MIXED, so use its own PK.
             */
            $table->foreign(
                'stored_file_id',
                'fk_documents_stored_file'
            )
                ->references('id')
                ->on('stored_files')
                ->restrictOnDelete();

            /*
             * Composite FK contains NOT NULL company_id,
             * therefore it cannot use SET NULL.
             */
            $table->foreign(
                ['company_id', 'superseded_by_document_id'],
                'fk_documents_superseded'
            )
                ->references(['company_id', 'id'])
                ->on('documents')
                ->restrictOnDelete();
        });

        /*
         * ================================================================
         * 2. DOCUMENT ↔ WASTE LOT
         * ================================================================
         */
        Schema::create('document_waste_lots', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('waste_lot_id');

            $table->datetimes();

            $table->unique(
                ['company_id', 'document_id', 'waste_lot_id'],
                'uq_document_waste_lots'
            );

            $table->index(
                ['company_id', 'document_id'],
                'ix_dwl_document'
            );

            $table->index(
                ['company_id', 'waste_lot_id'],
                'ix_dwl_waste_lot'
            );

            $table->foreign(
                'company_id',
                'fk_dwl_company'
            )
                ->references('id')
                ->on('companies')
                ->restrictOnDelete();

            $table->foreign(
                ['company_id', 'document_id'],
                'fk_dwl_document'
            )
                ->references(['company_id', 'id'])
                ->on('documents')
                ->cascadeOnDelete();

            $table->foreign(
                ['company_id', 'waste_lot_id'],
                'fk_dwl_waste_lot'
            )
                ->references(['company_id', 'id'])
                ->on('waste_lots')
                ->restrictOnDelete();
        });

        /*
         * ================================================================
         * 3. DOCUMENT SIGNATURES
         * ================================================================
         */
        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('document_id');

            $table->string('signer_party', 40);
            $table->unsignedInteger('sequence');

            $table->unsignedBigInteger('signer_company_id')->nullable();
            $table->unsignedBigInteger('signer_user_id')->nullable();

            $table->string('status', 40);

            $table->dateTime('signed_at')->nullable();
            $table->char('signed_payload_hash', 64)->nullable();

            $table->string('signature_method', 60)->nullable();

            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->unsignedBigInteger(
                'signature_image_stored_file_id'
            )->nullable();

            $table->datetimes();

            $table->unique(
                ['company_id', 'id'],
                'uq_document_signatures_company_id'
            );

            $table->unique(
                ['company_id', 'document_id', 'sequence'],
                'uq_docsig_sequence'
            );

            $table->index(
                ['company_id', 'signer_company_id'],
                'ix_docsig_signer_company'
            );

            $table->index(
                ['company_id', 'signer_user_id'],
                'ix_docsig_signer_user'
            );

            $table->foreign(
                'company_id',
                'fk_docsig_company'
            )
                ->references('id')
                ->on('companies')
                ->restrictOnDelete();

            $table->foreign(
                ['company_id', 'document_id'],
                'fk_docsig_document'
            )
                ->references(['company_id', 'id'])
                ->on('documents')
                ->cascadeOnDelete();

            $table->foreign(
                'signer_company_id',
                'fk_docsig_signer_company'
            )
                ->references('id')
                ->on('companies')
                ->nullOnDelete();

            $table->foreign(
                'signer_user_id',
                'fk_docsig_signer_user'
            )
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            /*
             * stored_files is MIXED.
             */
            $table->foreign(
                'signature_image_stored_file_id',
                'fk_docsig_signature_file'
            )
                ->references('id')
                ->on('stored_files')
                ->nullOnDelete();
        });

        /*
         * ================================================================
         * 4. HAZARDOUS WASTE MANIFESTS
         * ================================================================
         */
        Schema::create('hazardous_waste_manifests', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();

            $table->unsignedBigInteger('company_id');

            $table->string('manifest_number', 100);

            $table->unsignedBigInteger('pickup_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('document_id');

            $table->string('status', 40);

            $table->unsignedBigInteger('regulatory_waste_code_id');
            $table->unsignedBigInteger('waste_type_id');

            $table->unsignedBigInteger('packaging_type_id')->nullable();
            $table->unsignedBigInteger('treatment_channel_id')->nullable();

            $table->unsignedInteger('package_count')->nullable();

            $table->decimal('declared_weight_kg', 14, 3)->nullable();
            $table->decimal('received_weight_kg', 14, 3)->nullable();

            $table->json('producer_snapshot');
            $table->json('transporter_snapshot')->nullable();
            $table->json('receiver_snapshot');

            $table->unsignedBigInteger('transporter_company_id')->nullable();
            $table->unsignedBigInteger('receiver_company_id')->nullable();

            $table->dateTime('collected_at')->nullable();
            $table->dateTime('received_at')->nullable();

            $table->datetimes();

            $table->unique(
                ['company_id', 'id'],
                'uq_hwm_company_id'
            );

            $table->unique(
                ['company_id', 'pickup_id', 'regulatory_waste_code_id'],
                'uq_hwm_pickup_code'
            );

            $table->index(
                ['company_id', 'pickup_id'],
                'ix_hwm_company_pickup'
            );

            $table->index(
                ['company_id', 'status'],
                'ix_hwm_company_status'
            );

            $table->index(
                ['company_id', 'document_id'],
                'ix_hwm_document'
            );

            $table->index(
                ['company_id', 'waste_type_id'],
                'ix_hwm_waste_type'
            );

            $table->foreign(
                'company_id',
                'fk_hwm_company'
            )
                ->references('id')
                ->on('companies')
                ->restrictOnDelete();

            $table->foreign(
                ['company_id', 'pickup_id'],
                'fk_hwm_pickup'
            )
                ->references(['company_id', 'id'])
                ->on('pickups')
                ->restrictOnDelete();

            $table->foreign(
                ['company_id', 'site_id'],
                'fk_hwm_site'
            )
                ->references(['company_id', 'id'])
                ->on('sites')
                ->restrictOnDelete();

            $table->foreign(
                ['company_id', 'document_id'],
                'fk_hwm_document'
            )
                ->references(['company_id', 'id'])
                ->on('documents')
                ->restrictOnDelete();

            $table->foreign(
                'regulatory_waste_code_id',
                'fk_hwm_regulatory_code'
            )
                ->references('id')
                ->on('regulatory_waste_codes')
                ->restrictOnDelete();

            $table->foreign(
                ['company_id', 'waste_type_id'],
                'fk_hwm_waste_type'
            )
                ->references(['company_id', 'id'])
                ->on('waste_types')
                ->restrictOnDelete();

            $table->foreign(
                'packaging_type_id',
                'fk_hwm_packaging'
            )
                ->references('id')
                ->on('packaging_types')
                ->nullOnDelete();

            $table->foreign(
                'treatment_channel_id',
                'fk_hwm_treatment'
            )
                ->references('id')
                ->on('treatment_channels')
                ->nullOnDelete();

            $table->foreign(
                'transporter_company_id',
                'fk_hwm_transporter'
            )
                ->references('id')
                ->on('companies')
                ->nullOnDelete();

            $table->foreign(
                'receiver_company_id',
                'fk_hwm_receiver'
            )
                ->references('id')
                ->on('companies')
                ->nullOnDelete();
        });

        /*
         * ================================================================
         * 5. ANNUAL DECLARATIONS
         * ================================================================
         */
        Schema::create('annual_declarations', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('site_id');

            $table->string('format_code', 40);
            $table->unsignedSmallInteger('reporting_year');

            $table->string('status', 40);

            $table->json('data')->nullable();

            $table->dateTime('computed_at')->nullable();

            $table->unsignedBigInteger('validated_by_user_id')->nullable();
            $table->dateTime('validated_at')->nullable();

            $table->dateTime('submitted_at')->nullable();
            $table->string('submission_reference', 120)->nullable();

            $table->unsignedBigInteger('document_id')->nullable();

            $table->datetimes();

            $table->unique(
                [
                    'company_id',
                    'site_id',
                    'format_code',
                    'reporting_year',
                ],
                'uq_annual_declaration_period'
            );

            $table->unique(
                ['company_id', 'id'],
                'uq_annual_declarations_company_id'
            );

            $table->index(
                ['company_id', 'site_id', 'reporting_year', 'status'],
                'ix_annual_declaration_lookup'
            );

            $table->foreign(
                'company_id',
                'fk_annual_decl_company'
            )
                ->references('id')
                ->on('companies')
                ->restrictOnDelete();

            $table->foreign(
                ['company_id', 'site_id'],
                'fk_annual_decl_site'
            )
                ->references(['company_id', 'id'])
                ->on('sites')
                ->restrictOnDelete();

            $table->foreign(
                'validated_by_user_id',
                'fk_annual_decl_validator'
            )
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            /*
             * Composite FK contains NOT NULL company_id,
             * therefore it cannot use SET NULL.
             */
            $table->foreign(
                ['company_id', 'document_id'],
                'fk_annual_decl_document'
            )
                ->references(['company_id', 'id'])
                ->on('documents')
                ->restrictOnDelete();
        });

        /*
         * ================================================================
         * 6. DEFERRED pickup_lots → hazardous_waste_manifests
         * ================================================================
         */
        Schema::table('pickup_lots', function (Blueprint $table) {
            $table->foreign(
                ['company_id', 'hazardous_waste_manifest_id'],
                'fk_pickup_lots_hwm'
            )
                ->references(['company_id', 'id'])
                ->on('hazardous_waste_manifests')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pickup_lots', function (Blueprint $table) {
            $table->dropForeign('fk_pickup_lots_hwm');
        });

        Schema::dropIfExists('annual_declarations');
        Schema::dropIfExists('hazardous_waste_manifests');
        Schema::dropIfExists('document_signatures');
        Schema::dropIfExists('document_waste_lots');
        Schema::dropIfExists('documents');
    }
};
