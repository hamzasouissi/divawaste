@use('App\Modules\Common\Support\DocumentLanguage', 'Doc')
@use('Illuminate\Support\Carbon')
@php
    $party = fn (array $p) => e($p['legal_name'] ?? '').'<br>'.(($p['tax_id'] ?? null) ? e(__('documents.tax_id').__('documents.colon')).e($p['tax_id']).'<br>' : '').e($p['address'] ?? '').(($p['phone'] ?? null) ? '<br>'.e(__('documents.phone')).' '.e($p['phone']) : '');
    $kg = fn ($value) => $value === null ? '—' : Doc::number($value).' kg';
    // A lot counted in pieces or litres shows its quantity, and its weight beside it only when one is known.
    $measure = fn ($weight, $quantity, $unit) => $unit === null ? $kg($weight)
        : ($quantity === null ? '—' : rtrim(rtrim(number_format((float) $quantity, 3, ',', ' '), '0'), ',').' '.__('documents.units.'.$unit).((float) $weight > 0 ? ' ('.$kg($weight).')' : ''));
    // A slip that carries counted waste speaks of quantities; its total weight is printed only when there is one.
    $counted = collect($payload['lots'])->contains(fn ($lot) => ($lot['unit'] ?? null) !== null);
    $weighed = (float) $payload['declared_weight_kg'] > 0;
    $electronic = fn ($signature) => $signature->status === 'signed' && $signature->signature_method !== 'paper';
    $paper = fn ($signature) => $signature->status === 'signed' && $signature->signature_method === 'paper';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.head')
    <style>
        .box { border: .8pt solid #1a2227; padding: 6pt 8pt; margin-bottom: 8pt; }
        .box h3 { margin: 0 0 4pt 0; font-size: 8.5pt; text-transform: uppercase; }
        .cols { width: 100%; border-collapse: separate; border-spacing: 6pt 0; margin: 0 -6pt 8pt -6pt; }
        .cols td { border: .8pt solid #1a2227; padding: 6pt 8pt; vertical-align: top; width: 33.3%; font-size: 8pt; }
        .draft { color: #b3261e; font-weight: bold; font-size: 9pt; }
        .sign { margin-top: 10pt; }
        .sign td { height: 80pt; }
        .sign .line { color: #5b6770; font-size: 7pt; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td><h1>{{ __(($payload['is_hazardous'] ?? true) ? 'documents.manifest.title_hazardous' : 'documents.manifest.title') }}</h1>
            <div class="meta">{{ __('documents.manifest.meta', ['number' => $payload['manifest_number'], 'pickup' => $payload['pickup_number'], 'date' => Carbon::parse($payload['pickup_date'])->format('d/m/Y')]) }}</div></td>
        <td class="company">@unless ($complete)<span class="draft">{{ __('documents.manifest.signing') }}</span>@endunless</td>
    </tr>
</table>

<table class="cols">
    <tr>
        <td><strong>{{ __('documents.manifest.producer') }}</strong><br>{!! $party($payload['producer']) !!}<br>{{ __('documents.manifest.site') }}{{ __('documents.colon') }}{{ $payload['producer']['site'] ?? '' }} — {{ $payload['producer']['site_address'] ?? '' }}</td>
        <td><strong>{{ __('documents.manifest.transporter') }}</strong><br>{!! $party($payload['transporter']) !!}</td>
        <td><strong>{{ __('documents.manifest.receiver') }}</strong><br>{!! $party($payload['receiver']) !!}</td>
    </tr>
</table>

<div class="box">
    <h3>{{ __('documents.manifest.waste') }}</h3>
    @if ($payload['waste_code'] ?? null)
        {{ __('documents.manifest.code') }}{{ __('documents.colon') }}<strong>{{ $payload['waste_code'] }}</strong> — {{ $payload['waste_label'] }}<br>
    @endif
    {{ __('documents.manifest.designation') }}{{ __('documents.colon') }}{{ $payload['waste_type'] }} · {{ __('documents.manifest.packages') }}{{ __('documents.colon') }}{{ $payload['package_count'] }} · {{ __('documents.manifest.departure_weight') }}{{ __('documents.colon') }}<strong>{{ $weighed || ! $counted ? $kg($payload['declared_weight_kg']) : __('documents.manifest.not_weighed') }}</strong>
</div>

<table class="data">
    <thead><tr><th>{{ __('documents.manifest.lot_number') }}</th><th class="num">{{ __($counted ? 'documents.manifest.departure_quantity' : 'documents.manifest.departure_weight') }}</th><th class="num">{{ __($counted ? 'documents.manifest.received_quantity' : 'documents.manifest.received_weight') }}</th></tr></thead>
    <tbody>
    @foreach ($payload['lots'] as $lot)
        <tr><td>{{ $lot['lot_number'] }}</td><td class="num">{{ $measure($lot['weight_kg'], $lot['quantity'] ?? null, $lot['unit'] ?? null) }}</td><td class="num">{{ $measure($lot['received_weight_kg'], $lot['received_quantity'] ?? null, $lot['unit'] ?? null) }}</td></tr>
    @endforeach
    </tbody>
</table>

<h2>{{ __('documents.manifest.signatures') }}</h2>
<table class="data">
    <thead><tr><th>{{ __('documents.manifest.party') }}</th><th>{{ __('documents.manifest.signer') }}</th><th>{{ __('documents.manifest.method') }}</th><th>{{ __('documents.manifest.date') }}</th><th>{{ __('documents.manifest.hash') }}</th></tr></thead>
    <tbody>
    @foreach ($signatures as $signature)
        <tr>
            <td>{{ __('exports.signer.'.$signature->signer_party) }}</td>
            <td>{{ $signature->status === 'signed' ? ($signature->signer_name ?? trim($signature->first_name.' '.$signature->last_name)) : __('documents.manifest.pending') }}</td>
            <td>{{ $signature->status === 'signed' ? __('documents.manifest.'.($paper($signature) ? 'paper' : 'electronic')) : '—' }}</td>
            <td>
                @if ($paper($signature)) {{ Carbon::parse($signature->signed_at)->format('d/m/Y') }}
                @elseif ($signature->signed_at) {{ Carbon::parse($signature->signed_at, 'UTC')->timezone($timezone)->format('d/m/Y H:i') }}
                @else — @endif
            </td>
            <td style="font-family: DejaVu Sans Mono, monospace; font-size: 6pt;">{{ $signature->signed_payload_hash ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

{{-- A box to sign by hand for every party that has not signed electronically: the slip can be printed at any stage. --}}
@unless ($signatures->every($electronic))
    <table class="cols sign">
        <tr>
        @foreach ($signatures as $signature)
            <td>
                <strong>{{ __('exports.signer.'.$signature->signer_party) }}</strong><br>
                @if ($electronic($signature))
                    <span class="line">{{ __('documents.manifest.electronic') }}</span>
                @else
                    <span class="line">{{ __('documents.manifest.by_hand_name') }}{{ __('documents.colon') }}{{ $signature->signer_name }}</span><br>
                    <span class="line">{{ __('documents.manifest.by_hand_date') }}{{ __('documents.colon') }}{{ $paper($signature) ? Carbon::parse($signature->signed_at)->format('d/m/Y') : '' }}</span><br>
                    <span class="line">{{ __('documents.manifest.by_hand') }}</span>
                @endif
            </td>
        @endforeach
        </tr>
    </table>
@endunless
<p class="note">{{ __('documents.manifest.note') }}</p>
</body>
</html>
