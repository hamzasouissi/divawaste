<?php

namespace App\Modules\Reporting\Models;

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteDailyStat extends Model
{
    use BelongsToCompany;

    protected $table = 'waste_daily_stats';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'generated_kg' => 'decimal:3',
            'generated_lot_count' => 'integer',
            'disposed_kg' => 'decimal:3',
            'disposed_lot_count' => 'integer',
            'net_amount' => 'decimal:3',
            'computed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function wasteType(): BelongsTo
    {
        return $this->belongsTo(WasteType::class);
    }
}
