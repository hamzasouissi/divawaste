<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Support\Amount;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Line amounts are derived (quantity × unit, tax on the line). Editable only while the invoice is a draft.
 */
class InvoiceItem extends Model
{
    use BelongsToCompany;

    protected $attributes = ['tax_rate_pct' => '0.000', 'sort_order' => 0];

    protected $fillable = [
        'invoice_id', 'item_type', 'description', 'quantity', 'unit_amount', 'tax_rate_id', 'tax_rate_pct',
        'subscription_plan_price_id', 'usage_metric', 'usage_details', 'period_start', 'period_end', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_amount' => 'decimal:3',
            'tax_rate_pct' => 'decimal:3',
            'line_subtotal' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'line_total' => 'decimal:3',
            'usage_details' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $draft = Invoice::query()->whereKey($item->invoice_id)->where('status', 'draft')->exists();
            if (! $draft) {
                throw new BusinessRuleViolation('INVOICE_IMMUTABLE', 'Lines can only change on a draft invoice.');
            }

            $subtotal = Amount::multiply((string) $item->quantity, (string) $item->unit_amount);
            $tax = Amount::percent($subtotal, (string) $item->tax_rate_pct);
            $item->line_subtotal = $subtotal;
            $item->tax_amount = $tax;
            $item->line_total = Amount::add($subtotal, $tax);
        });
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
