<?php

namespace App\Modules\Waste\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * QR / RFID identifier. Values are globally unique and never reused; one active tag per type per lot.
 */
class LotTag extends Model
{
    use BelongsToCompany;

    protected $attributes = ['status' => 'active', 'is_active_flag' => true];

    protected $fillable = ['waste_lot_id', 'tag_type', 'tag_value', 'status', 'is_active_flag', 'assigned_at', 'assigned_by_user_id'];

    protected function casts(): array
    {
        return ['is_active_flag' => 'boolean', 'assigned_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
