<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fiscal document: once issued, number, amounts and snapshots never change (corrections = credit notes).
 */
class Invoice extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    public const FROZEN_WHEN_ISSUED = [
        'invoice_number', 'invoice_type', 'issue_date', 'currency_code', 'subtotal_amount', 'tax_amount',
        'stamp_duty_amount', 'total_amount', 'seller_snapshot', 'buyer_snapshot', 'period_start', 'period_end',
    ];

    protected $attributes = [
        'invoice_type' => 'invoice',
        'status' => 'draft',
        'subtotal_amount' => '0.000',
        'tax_amount' => '0.000',
        'stamp_duty_amount' => '0.000',
        'total_amount' => '0.000',
        'amount_paid' => '0.000',
    ];

    protected $fillable = [
        'ulid', 'company_subscription_id', 'invoice_type', 'credited_invoice_id', 'due_date', 'period_start', 'period_end',
        'currency_code', 'stamp_duty_amount', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'subtotal_amount' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'stamp_duty_amount' => 'decimal:3',
            'total_amount' => 'decimal:3',
            'amount_paid' => 'decimal:3',
            'seller_snapshot' => 'array',
            'buyer_snapshot' => 'array',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice) {
            if ($invoice->getOriginal('status') !== InvoiceStatus::Draft && $invoice->isDirty(self::FROZEN_WHEN_ISSUED)) {
                throw new BusinessRuleViolation('INVOICE_IMMUTABLE', 'An issued invoice cannot be modified.');
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
