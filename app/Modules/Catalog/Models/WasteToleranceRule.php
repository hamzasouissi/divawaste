<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The gap accepted between what was declared and what was measured, for a waste type and/or a way of handling it.
 * Without a type the rule is for every waste; without a channel, for whatever is done with it.
 *
 * @property string $ulid
 */
class WasteToleranceRule extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $attributes = ['direction' => 'both', 'basis' => 'operational', 'is_active' => true];

    protected $fillable = ['waste_type_id', 'treatment_channel_id', 'warning_pct', 'blocking_pct', 'direction', 'basis', 'reference', 'notes',
        'is_active', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['warning_pct' => 'decimal:2', 'blocking_pct' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<WasteType, $this>
     */
    public function wasteType(): BelongsTo
    {
        return $this->belongsTo(WasteType::class);
    }
}
