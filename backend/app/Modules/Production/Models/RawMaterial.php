<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A raw material (fabric, yarn, accessory): its supplier, composition and colour are copied onto the waste that comes from it.
 *
 * @property string $ulid
 */
class RawMaterial extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    /** What a raw material is: what it is used for, and so how its waste is estimated by default. */
    public const KINDS = ['fabric', 'thread', 'trim', 'packaging', 'chemical', 'other'];

    /**
     * The waste line a single type means, by default, for a material of this kind: a fabric's cutting waste, a
     * packaging thrown away whole, a part of what is consumed for the rest (its rate still to say).
     *
     * @return array{type: int, method: string, pct: string|null, rate: null, per_unit: null}
     */
    public static function defaultWasteLine(int $type, ?string $kind, bool $metres): array
    {
        $method = match (true) {
            $kind === 'fabric' || ($kind === null && $metres) => 'cutting',
            $kind === 'packaging' => 'whole',
            default => 'loss_rate',
        };

        return ['type' => $type, 'method' => $method, 'pct' => $method === 'cutting' ? '100.00' : null, 'rate' => null, 'per_unit' => null];
    }

    protected $fillable = ['ulid', 'reference', 'name', 'category', 'kind', 'production_supplier_id', 'unit_id', 'color_family_id', 'color_label', 'grammage_gsm', 'width_cm', 'unit_weight_kg', 'composition', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'composition' => 'array', 'grammage_gsm' => 'decimal:2', 'width_cm' => 'decimal:2'];
    }
}
