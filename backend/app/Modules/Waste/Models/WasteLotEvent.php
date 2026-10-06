<?php

namespace App\Modules\Waste\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use App\Modules\Waste\Enums\LotStatus;
use App\Modules\Waste\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only lot timeline: the cahier's "Mouvement de lot" (+ weighings, transformations, tags).
 */
class WasteLotEvent extends Model
{
    use AppendOnly;
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'waste_lot_id', 'event_type', 'from_status', 'to_status', 'from_site_id', 'to_site_id', 'from_zone_id', 'to_zone_id',
        'weight_kg', 'gross_weight_kg', 'tare_weight_kg', 'weight_source', 'device_reference', 'pickup_id',
        'waste_lot_operation_id', 'payload', 'source', 'occurred_at', 'recorded_at', 'user_id', 'actor_company_id',
        'device_id', 'sync_operation_id',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => LotStatus::class,
            'to_status' => LotStatus::class,
            'weight_kg' => 'decimal:3',
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function record(WasteLot $lot, string $type, array $attributes = []): self
    {
        return self::query()->create($attributes + [
            'waste_lot_id' => $lot->id,
            'event_type' => $type,
            'source' => 'web',
            'occurred_at' => now(),
            'recorded_at' => now(),
            'user_id' => auth()->id(),
        ]);
    }
}
