<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Facades\DB;

class IssueDocument
{
    public function handle(Document $document): Document
    {
        return DB::transaction(function () use ($document): Document {
            $document->refresh();

            if ($document->status === DocumentStatus::Issued) {
                return $document;
            }

            if ($document->status === DocumentStatus::Cancelled) {
                throw new BusinessRuleViolation(
                    'DOCUMENT_CANCELLED',
                    'A cancelled document cannot be issued.'
                );
            }

            if ($document->status === DocumentStatus::Superseded) {
                throw new BusinessRuleViolation(
                    'DOCUMENT_SUPERSEDED',
                    'A superseded document cannot be issued.'
                );
            }

            if ($document->stored_file_id === null) {
                throw new BusinessRuleViolation(
                    'DOCUMENT_FILE_REQUIRED',
                    'A document must have a stored file before it can be issued.'
                );
            }

            if ($document->issued_at === null) {
                $document->issued_at = now();
            }

            if ($document->retention_until === null) {
                $document->retention_until = now()->addYears(10)->toDateString();
            }

            $document->status = DocumentStatus::Issued;

            $document->save();

            return $document->fresh();
        });
    }
}
