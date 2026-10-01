<?php

namespace App\Modules\Waste\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use App\Modules\Waste\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class WasteLotOperation extends Model
{
    use AppendOnly;
    use BelongsToCompany;
    use HasPublicUlid;

    public $timestamps = false;

    protected $fillable = [
        'ulid', 'operation_type', 'site_id', 'input_total_kg', 'output_total_kg', 'notes', 'performed_by_user_id',
        'performed_at', 'device_id', 'sync_operation_id', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['input_total_kg' => 'decimal:3', 'output_total_kg' => 'decimal:3', 'performed_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
