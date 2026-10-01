<?php

namespace App\Modules\Sync\Models;

use App\Modules\Sync\Enums\SyncOperationStatus;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Processed offline operation; client_operation_id is the idempotency key (blueprint §7).
 * Rows are written in the same transaction as the domain effect by the operation handlers.
 */
class SyncOperation extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'user_id', 'client_operation_id', 'client_sequence', 'operation_type', 'entity_ulid', 'payload',
        'payload_hash', 'client_occurred_at', 'adjusted_occurred_at', 'received_at', 'processed_at', 'status', 'result',
        'error_code', 'conflict_type', 'server_state', 'resolution_status',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncOperationStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'server_state' => 'array',
            'client_sequence' => 'integer',
            'client_occurred_at' => 'immutable_datetime',
            'adjusted_occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Same key with a different payload is a client bug (IDEMPOTENCY_KEY_REUSED).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hashPayload(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
