<?php

namespace App\Modules\Waste\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WasteLotComposition extends Model
{
    use BelongsToCompany;

    protected $fillable = ['waste_lot_id', 'material_id', 'percentage'];

    protected function casts(): array
    {
        return ['percentage' => 'decimal:2'];
    }
}
