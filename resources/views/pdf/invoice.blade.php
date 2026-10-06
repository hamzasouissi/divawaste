@use('App\Modules\Common\Support\DocumentLanguage', 'Doc')
@php
    $money = fn ($value) => Doc::number($value);
    $seller = $invoice->seller_snapshot ?? [];
    $buyer = $invoice->buyer_snapshot ?? [];
    // A subscription line is described from its period, in the language of the document; any other line keeps its own text.
    $describe = fn ($item) => in_array($item->item_type, ['site', 'subscription'], true) && $item->period_start && $item->period_end
        ? __('documents.invoice.item.'.$item->item_type, ['from' => $item->period_start->format('d/m/Y'), 'to' => $item->period_end->format('d/m/Y')])
        : $item->description;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8">@include('pdf.partials.head')
    <style> .parties { width: 100%; margin-bottom: 14pt; } .parties td { width: 50%; vertical-align: top; font-size: 8.5pt; }
        .totals { width: 45%; margin-left: 55%; margin-top: 8pt; border-collapse: collapse; } .totals td { padding: 3pt 5pt; font-size: 8.5pt; }
        .totals .grand td { border-top: 1pt solid #1a2227; font-weight: bold; font-size: 10pt; } </style>
</head>
<body>
<table class="head">
    <tr>
        <td><h1>{{ __('documents.invoice.number', ['type' => __('documents.invoice.'.($invoice->invoice_type === 'credit_note' ? 'credit_note' : 'invoice')), 'number' => $invoice->invoice_number]) }}</h1>
            <div class="meta">{{ __('documents.invoice.date', ['date' => $invoice->issue_date->format('d/m/Y')]) }} · {{ __('documents.invoice.due', ['date' => $invoice->due_date?->format('d/m/Y') ?? '—']) }}
                @if ($invoice->period_start) · {{ __('documents.period', ['from' => $invoice->period_start->format('d/m/Y'), 'to' => $invoice->period_end->format('d/m/Y')]) }} @endif</div></td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td><strong>{{ $seller['legal_name'] ?? '' }}</strong><br>
            @if ($seller['tax_id'] ?? null) {{ __('documents.tax_id') }}{{ __('documents.colon') }}{{ $seller['tax_id'] }}<br>@endif
            @if ($seller['vat_number'] ?? null) {{ __('documents.vat_number') }}{{ __('documents.colon') }}{{ $seller['vat_number'] }}<br>@endif
            {{ $seller['address'] ?? '' }}</td>
        <td><strong>{{ __('documents.invoice.billed_to') }} {{ $buyer['legal_name'] ?? '' }}</strong><br>
            {{ __('documents.tax_id') }}{{ __('documents.colon') }}{{ $buyer['tax_id'] ?? '—' }}<br>
            @if ($buyer['vat_number'] ?? null) {{ __('documents.vat_number') }}{{ __('documents.colon') }}{{ $buyer['vat_number'] }}<br>@endif
            {{ trim(($buyer['address_line1'] ?? '').' '.($buyer['postal_code'] ?? '').' '.($buyer['city'] ?? '')) }} {{ $buyer['country'] ?? '' }}</td>
    </tr>
</table>

<table class="data">
    <thead><tr><th>{{ __('documents.invoice.designation') }}</th><th class="num">{{ __('documents.invoice.quantity') }}</th><th class="num">{{ __('documents.invoice.unit_price') }}</th>
        <th class="num">{{ __('documents.invoice.vat') }}</th><th class="num">{{ __('documents.invoice.amount') }}</th></tr></thead>
    <tbody>
    @foreach ($items as $item)
        <tr><td>{{ $describe($item) }}</td><td class="num">{{ Doc::trimmed($item->quantity) }}</td><td class="num">{{ $money($item->unit_amount) }}</td>
            <td class="num">{{ Doc::trimmed($item->tax_rate_pct) }} %</td><td class="num">{{ $money($item->line_subtotal) }}</td></tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ __('documents.invoice.total_excl') }}</td><td class="num" style="text-align:right">{{ $money($invoice->subtotal_amount) }} {{ $invoice->currency_code }}</td></tr>
    <tr><td>{{ __('documents.invoice.vat') }}</td><td style="text-align:right">{{ $money($invoice->tax_amount) }} {{ $invoice->currency_code }}</td></tr>
    @if ((float) $invoice->stamp_duty_amount > 0)
        <tr><td>{{ __('documents.invoice.stamp_duty') }}</td><td style="text-align:right">{{ $money($invoice->stamp_duty_amount) }} {{ $invoice->currency_code }}</td></tr>
    @endif
    <tr class="grand"><td>{{ __('documents.invoice.total_incl') }}</td><td style="text-align:right">{{ $money($invoice->total_amount) }} {{ $invoice->currency_code }}</td></tr>
</table>

<p class="note">{{ __('documents.invoice.payment') }}</p>
</body>
</html>
