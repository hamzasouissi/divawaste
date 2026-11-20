<?php

namespace App\Modules\Waste\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use App\Modules\Waste\Enums\ScrapCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reason the company scraps material for (motif de rebut): lots name it, so they can be counted, weighed and valued
 * per reason. Deactivated, never deleted, once lots name it.
 *
 * @property string $ulid
 * @property string $code
 * @property string $label
 * @property ScrapCategory|null $category
 * @property string|null $color
 * @property bool $is_active
 */
class ScrapReason extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $attributes = ['is_active' => true, 'sort_order' => 1000];

    protected $fillable = ['ulid', 'code', 'label', 'category', 'color', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['category' => ScrapCategory::class, 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * @return HasMany<WasteLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(WasteLot::class);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['ulid' => $this->ulid, 'code' => $this->code, 'label' => $this->label, 'category' => $this->category?->value, 'color' => $this->color];
    }
}
