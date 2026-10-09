@use('App\Modules\Common\Support\DocumentLanguage', 'Doc')
@use('Illuminate\Support\Carbon')
@php
    $kg = fn ($value) => $value === null ? '—' : Doc::number($value).' kg';
    $label = fn ($row) => is_array($row['label']) ? ($row['label'][app()->getLocale()] ?? reset($row['label'])) : $row['label'];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.head')
</head>
<body>
<table class="head">
    <tr>
        <td><h1>{{ __('exports.production.sheet') }}</h1>
            <div class="meta">{{ __('documents.period', ['from' => Carbon::parse($stats['period']['from'])->format('d/m/Y'), 'to' => Carbon::parse($stats['period']['to'])->format('d/m/Y')]) }}@if ($site) — {{ $site }}@endif</div></td>
        <td class="company">{{ $company }}</td>
    </tr>
</table>

<table class="kpis">
    <tr>
        <td><div class="label">{{ __('documents.report.generated') }}</div><div class="value">{{ $kg($stats['totals']['waste_kg']) }}</div></td>
        <td><div class="label">{{ __('exports.production.measured') }}</div><div class="value">{{ $kg($stats['totals']['measured_kg']) }}</div></td>
        <td><div class="label">{{ __('exports.production.estimated') }}</div><div class="value">{{ $kg($stats['totals']['estimated_kg']) }}</div></td>
        <td><div class="label">{{ __('exports.production.unattributed') }}</div><div class="value">{{ $kg($stats['totals']['unattributed_kg']) }}</div></td>
    </tr>
</table>
@if (($stats['totals']['unweighed_lots'] ?? 0) > 0)<p class="note">{{ __('documents.unweighed_note', ['count' => $stats['totals']['unweighed_lots']]) }}</p>@endif

@foreach (['by_order', 'by_client', 'by_article', 'by_step', 'by_material', 'by_supplier'] as $section)
    @if ($stats[$section] !== [])
        <h2>{{ __('exports.production.'.$section) }}</h2>
        <table class="data">
            <thead>
            <tr>
                <th>{{ __('exports.production.columns.label') }}</th><th class="num">{{ __('exports.production.columns.lots') }}</th>
                <th class="num">{{ __('exports.production.columns.measured_kg') }}</th><th class="num">{{ __('exports.production.columns.estimated_kg') }}</th>
                <th class="num">{{ __('exports.production.columns.total_kg') }}</th><th class="num">{{ __('exports.production.columns.pieces') }}</th>
                <th class="num">{{ __('exports.production.columns.g_per_piece') }}</th><th class="num">{{ __('exports.production.columns.waste_rate_pct') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($stats[$section] as $row)
                <tr>
                    <td><strong>{{ $label($row) }}</strong>@if ($row['sublabel']) — {{ $row['sublabel'] }}@endif</td>
                    <td class="num">{{ $row['lots'] }}</td>
                    <td class="num">{{ Doc::number($row['measured_kg']) }}</td>
                    <td class="num">{{ (float) $row['estimated_kg'] > 0 ? '≈ '.Doc::number($row['estimated_kg']) : '—' }}</td>
                    <td class="num"><strong>{{ Doc::number($row['total_kg']) }}</strong></td>
                    <td class="num">{{ $row['pieces'] === null ? '—' : number_format($row['pieces'], 0, ',', ' ') }}</td>
                    <td class="num">{{ $row['g_per_piece'] === null ? '—' : Doc::number($row['g_per_piece']) }}</td>
                    <td class="num">{{ $row['waste_rate_pct'] === null ? '—' : Doc::number($row['waste_rate_pct']).' %' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endforeach

@if ($stats['monthly'] !== [])
    <h2>{{ __('documents.report.monthly') }}</h2>
    <table class="data">
        <thead><tr><th>{{ __('documents.report.month') }}</th><th class="num">{{ __('exports.production.columns.measured_kg') }}</th><th class="num">{{ __('exports.production.columns.estimated_kg') }}</th>
            <th class="num">{{ __('exports.production.unattributed') }} (kg)</th></tr></thead>
        <tbody>
        @foreach ($stats['monthly'] as $month)
            <tr><td>{{ Carbon::parse($month['month'].'-01')->format('m/Y') }}</td><td class="num">{{ Doc::number($month['measured_kg']) }}</td>
                <td class="num">{{ Doc::number($month['estimated_kg']) }}</td><td class="num">{{ Doc::number($month['unattributed_kg']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endif

<p class="note">{{ __('exports.production.method') }}</p>
<div class="foot">{{ __('documents.generated', ['date' => now()->timezone($timezone)->format('d/m/Y'), 'time' => now()->timezone($timezone)->format('H:i')]) }} — {{ __('documents.footer') }}</div>
</body>
</html>
