<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'ulid', 'invoice_id', 'method', 'amount', 'currency_code', 'bank_reference', 'declared_paid_on',
        'proof_stored_file_id', 'declared_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:3',
            'declared_paid_on' => 'date',
            'validated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
