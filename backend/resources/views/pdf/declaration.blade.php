@use('App\Modules\Common\Support\DocumentLanguage', 'Doc')
@php
    $kg = fn ($value) => Doc::number($value);
    $yesNo = fn ($value) => __($value ? 'documents.yes' : 'documents.no');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8">@include('pdf.partials.head')</head>
<body>
@include('pdf.partials.header')

<p>{{ __('documents.declaration.site') }}{{ __('documents.colon') }}<strong>{{ $data['producer']['site'] }}</strong> ({{ $data['producer']['site_code'] }}) — {{ $data['producer']['address'] }}
    @if ($data['producer']['regulatory_identifier']) · {{ __('documents.declaration.regulatory_identifier') }}{{ __('documents.colon') }}{{ $data['producer']['regulatory_identifier'] }} @endif
    · {{ __('documents.declaration.status') }}{{ __('documents.colon') }}<strong>{{ __('documents.declaration.'.($declaration->status->value === 'validated' ? 'validated' : 'draft')) }}</strong></p>

<table class="kpis">
    <tr>
        <td><div class="label">{{ __('documents.declaration.produced') }}</div><div class="value">{{ $kg($data['totals']['produced_kg']) }} kg</div></td>
        <td><div class="label">{{ __('documents.declaration.hazardous_produced') }}</div><div class="value">{{ $kg($data['totals']['hazardous_produced_kg']) }} kg</div></td>
        <td><div class="label">{{ __('documents.declaration.evacuated') }}</div><div class="value">{{ $kg($data['totals']['evacuated_kg']) }} kg</div></td>
        <td><div class="label">{{ __('documents.declaration.stock_end') }}</div><div class="value">{{ $kg($data['totals']['stock_at_year_end_kg']) }} kg</div></td>
    </tr>
</table>
@if (($data['totals']['unweighed_lots'] ?? 0) > 0)<p class="note">{{ __('documents.unweighed_note', ['count' => $data['totals']['unweighed_lots']]) }}</p>@endif

<h2>{{ __('documents.declaration.produced_title') }}</h2>
<table class="data">
    <thead><tr><th>{{ __('documents.declaration.waste_code') }}</th><th>{{ __('documents.declaration.designation') }}</th><th>{{ __('documents.declaration.hazardous') }}</th>
        <th class="num">{{ __('documents.declaration.lots') }}</th><th class="num">{{ __('documents.declaration.quantity') }}</th></tr></thead>
    <tbody>
    @forelse ($data['produced'] as $row)
        <tr><td>{{ $row['regulatory_code'] ?? '—' }}</td><td>{{ $row['waste_type'] }}</td><td>{{ $yesNo($row['is_hazardous']) }}</td>
            <td class="num">{{ $row['lots'] }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
    @empty
        <tr><td colspan="5">{{ __('documents.declaration.none_produced') }}</td></tr>
    @endforelse
    </tbody>
</table>

<h2>{{ __('documents.declaration.evacuated_title') }}</h2>
<table class="data">
    <thead><tr><th>{{ __('documents.declaration.waste_code') }}</th><th>{{ __('documents.declaration.designation') }}</th><th>{{ __('documents.declaration.hazardous') }}</th>
        <th>{{ __('documents.declaration.receiver') }}</th><th>{{ __('documents.declaration.channel') }}</th><th class="num">{{ __('documents.declaration.quantity') }}</th></tr></thead>
    <tbody>
    @forelse ($data['evacuated'] as $row)
        <tr><td>{{ $row['regulatory_code'] ?? '—' }}</td><td>{{ $row['waste_type'] }}</td><td>{{ $yesNo($row['is_hazardous']) }}</td>
            <td>{{ $row['provider'] }}</td><td>{{ $row['channel'] ? __('exports.channel.'.$row['channel']) : __('documents.declaration.channel_undeclared') }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
    @empty
        <tr><td colspan="6">{{ __('documents.declaration.none_evacuated') }}</td></tr>
    @endforelse
    </tbody>
</table>

<p class="note">{{ __('documents.declaration.note', ['date' => $declaration->computed_at?->format('d/m/Y')]) }}
    {{ __('documents.declaration.reference', ['reference' => $declaration->submission_reference ?? __('documents.declaration.reference_pending')]) }}</p>
</body>
</html>
