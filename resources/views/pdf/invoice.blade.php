@php
    $money = fn ($value) => number_format((float) $value, 3, ',', ' ');
    $seller = $invoice->seller_snapshot ?? [];
    $buyer = $invoice->buyer_snapshot ?? [];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8">@include('pdf.partials.head')
    <style> .parties { width: 100%; margin-bottom: 14pt; } .parties td { width: 50%; vertical-align: top; font-size: 8.5pt; }
        .totals { width: 45%; margin-left: 55%; margin-top: 8pt; border-collapse: collapse; } .totals td { padding: 3pt 5pt; font-size: 8.5pt; }
        .totals .grand td { border-top: 1pt solid #1a2227; font-weight: bold; font-size: 10pt; } </style>
</head>
<body>
<table class="head">
    <tr>
        <td><h1>{{ $invoice->invoice_type === 'credit_note' ? 'Avoir' : 'Facture' }} n° {{ $invoice->invoice_number }}</h1>
            <div class="meta">Date : {{ $invoice->issue_date->format('d/m/Y') }} · Échéance : {{ $invoice->due_date?->format('d/m/Y') ?? '—' }}
                @if ($invoice->period_start) · Période du {{ $invoice->period_start->format('d/m/Y') }} au {{ $invoice->period_end->format('d/m/Y') }} @endif</div></td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td><strong>{{ $seller['legal_name'] ?? '' }}</strong><br>
            @if ($seller['tax_id'] ?? null) Matricule fiscal : {{ $seller['tax_id'] }}<br>@endif
            @if ($seller['vat_number'] ?? null) N° TVA : {{ $seller['vat_number'] }}<br>@endif
            {{ $seller['address'] ?? '' }}</td>
        <td><strong>Facturé à : {{ $buyer['legal_name'] ?? '' }}</strong><br>
            Matricule fiscal : {{ $buyer['tax_id'] ?? '—' }}<br>
            @if ($buyer['vat_number'] ?? null) N° TVA : {{ $buyer['vat_number'] }}<br>@endif
            {{ trim(($buyer['address_line1'] ?? '').' '.($buyer['postal_code'] ?? '').' '.($buyer['city'] ?? '')) }} {{ $buyer['country'] ?? '' }}</td>
    </tr>
</table>

<table class="data">
    <thead><tr><th>Désignation</th><th class="num">Quantité</th><th class="num">Prix unitaire HT</th><th class="num">TVA</th><th class="num">Montant HT</th></tr></thead>
    <tbody>
    @foreach ($items as $item)
        <tr><td>{{ $item->description }}</td><td class="num">{{ rtrim(rtrim($money($item->quantity), '0'), ',') }}</td><td class="num">{{ $money($item->unit_amount) }}</td>
            <td class="num">{{ rtrim(rtrim(number_format((float) $item->tax_rate_pct, 3, ',', ''), '0'), ',') }} %</td><td class="num">{{ $money($item->line_subtotal) }}</td></tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Total HT</td><td class="num" style="text-align:right">{{ $money($invoice->subtotal_amount) }} {{ $invoice->currency_code }}</td></tr>
    <tr><td>TVA</td><td style="text-align:right">{{ $money($invoice->tax_amount) }} {{ $invoice->currency_code }}</td></tr>
    @if ((float) $invoice->stamp_duty_amount > 0)
        <tr><td>Droit de timbre</td><td style="text-align:right">{{ $money($invoice->stamp_duty_amount) }} {{ $invoice->currency_code }}</td></tr>
    @endif
    <tr class="grand"><td>Total TTC</td><td style="text-align:right">{{ $money($invoice->total_amount) }} {{ $invoice->currency_code }}</td></tr>
</table>

<p class="note">Règlement par virement bancaire. Merci d'indiquer le numéro de facture dans le libellé du virement, puis de le déclarer dans « Mon abonnement ».</p>
</body>
</html>
