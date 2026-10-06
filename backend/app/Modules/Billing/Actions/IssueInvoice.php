<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Support\Amount;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Draft → issued: totals from lines, buyer/seller snapshots and a gapless number taken under a row lock
 * in the same transaction (a rollback releases the number).
 */
final class IssueInvoice
{
    public function handle(Invoice $invoice, ?User $actor = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $invoice->isDraft()) {
                throw new BusinessRuleViolation('INVOICE_NOT_DRAFT', 'Only a draft invoice can be issued.');
            }

            $lines = InvoiceItem::query()->where('invoice_id', $invoice->id)->get(['line_subtotal', 'tax_amount']);
            if ($lines->isEmpty()) {
                throw new BusinessRuleViolation('INVOICE_EMPTY', 'An invoice needs at least one line.');
            }

            $subtotal = $tax = '0.000';
            foreach ($lines as $line) {
                $subtotal = Amount::add($subtotal, (string) $line->line_subtotal);
                $tax = Amount::add($tax, (string) $line->tax_amount);
            }

            $issueDate = now()->startOfDay();
            $invoice->forceFill([
                'invoice_number' => $this->nextNumber($invoice->invoice_type === 'credit_note' ? 'CN' : 'INV', (int) $issueDate->year),
                'status' => InvoiceStatus::Issued,
                'issue_date' => $issueDate,
                'subtotal_amount' => $subtotal,
                'tax_amount' => $tax,
                'total_amount' => Amount::add(Amount::add($subtotal, $tax), (string) $invoice->stamp_duty_amount),
                'seller_snapshot' => config('billing.seller'),
                'buyer_snapshot' => $this->buyerSnapshot((int) $invoice->company_id),
                'issued_at' => now(),
                'issued_by_user_id' => $actor?->id,
            ])->save();

            return $invoice;
        });
    }

    private function nextNumber(string $series, int $year): string
    {
        $prefix = config('billing.invoice_prefix').'-'.($series === 'CN' ? 'CN-' : '').$year.'-';
        DB::table('invoice_number_sequences')->insertOrIgnore([
            'series' => $series, 'fiscal_year' => $year, 'prefix' => $prefix, 'next_number' => 1, 'padding' => 6,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sequence = DB::table('invoice_number_sequences')->where('series', $series)->where('fiscal_year', $year)
            ->lockForUpdate()->first(['id', 'prefix', 'next_number', 'padding']);

        DB::table('invoice_number_sequences')->where('id', $sequence->id)
            ->update(['next_number' => $sequence->next_number + 1, 'updated_at' => now()]);

        return $sequence->prefix.str_pad((string) $sequence->next_number, (int) $sequence->padding, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function buyerSnapshot(int $companyId): array
    {
        $company = DB::table('companies as c')->join('countries as k', 'k.id', '=', 'c.country_id')
            ->where('c.id', $companyId)
            ->first(['c.legal_name', 'c.tax_id', 'c.vat_number', 'c.address_line1', 'c.address_line2', 'c.city', 'c.postal_code', 'k.iso2 as country']);

        return (array) $company;
    }
}
