@use('App\Modules\Common\Support\DocumentLanguage', 'Doc')
@php
    $kg = fn ($value) => Doc::number($value, 0).' kg';
    $t = fn ($value) => Doc::number((float) $value / 1000, 2).' t';
    $pct = fn ($value) => $value === null ? '—' : Doc::number($value, 1).' %';
    $money = fn ($value) => Doc::number($value).' '.$company->currency_code;
    $yesNo = fn ($value) => __($value ? 'documents.yes' : 'documents.no');
    $totals = $data['totals'];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8">@include('pdf.partials.head')</head>
<body>
@include('pdf.partials.header')

@unless ($economicOnly)
    <table class="kpis">
        <tr>
            <td><div class="label">{{ __('documents.report.generated') }}</div><div class="value">{{ $t($totals['generated_kg']) }}</div></td>
            <td><div class="label">{{ __('documents.report.recovery_rate') }}</div><div class="value">{{ $pct($totals['recovery_rate_pct']) }}</div></td>
            <td><div class="label">{{ __('documents.report.landfill_share') }}</div><div class="value">{{ $pct($totals['landfill_share_pct']) }}</div></td>
            <td><div class="label">{{ __('documents.report.kg_per_piece') }}</div><div class="value">{{ $totals['kg_per_piece'] === null ? '—' : Doc::number($totals['kg_per_piece'], 4) }}</div></td>
        </tr>
    </table>

    <h2>{{ __('documents.report.by_type') }}</h2>
    <table class="data">
        <thead><tr><th>{{ __('documents.report.type') }}</th><th>{{ __('documents.report.hazardous') }}</th><th class="num">{{ __('documents.report.lots') }}</th><th class="num">{{ __('documents.report.quantity') }}</th></tr></thead>
        <tbody>
        @forelse ($data['by_waste_type'] as $row)
            <tr><td>{{ $row['name'] }}</td><td>{{ $yesNo($row['is_hazardous']) }}</td><td class="num">{{ $row['lots'] }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
        @empty
            <tr><td colspan="4">{{ __('documents.report.none_generated') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>{{ __('documents.report.by_channel') }}</h2>
    <table class="data">
        <thead><tr><th>{{ __('documents.report.channel') }}</th><th>{{ __('documents.report.recovery') }}</th><th class="num">{{ __('documents.report.evacuated_quantity') }}</th></tr></thead>
        <tbody>
        @forelse ($data['by_channel'] as $row)
            <tr><td>{{ $row['channel'] === null ? __('documents.report.channel_undeclared') : __('exports.channel.'.$row['channel']) }}</td>
                <td>{{ $row['channel'] === null ? '—' : $yesNo($row['is_valorization']) }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
        @empty
            <tr><td colspan="3">{{ __('documents.report.none_evacuated') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>{{ __('documents.report.by_site') }}</h2>
    <table class="data">
        <thead><tr><th>{{ __('documents.report.site') }}</th><th class="num">{{ __('documents.report.lots') }}</th><th class="num">{{ __('documents.report.quantity') }}</th></tr></thead>
        <tbody>
        @foreach ($data['by_site'] as $row)
            <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['lots'] }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endunless

<h2>{{ __($economicOnly ? 'documents.report.monthly_balance' : 'documents.report.monthly') }}</h2>
<table class="data">
    <thead><tr><th>{{ __('documents.report.month') }}</th>@unless ($economicOnly)<th class="num">{{ __('documents.report.generated_short') }}</th>@endunless
        <th class="num">{{ __('documents.report.evacuated_short') }}</th><th class="num">{{ __('documents.report.net_balance') }}</th></tr></thead>
    <tbody>
    @foreach ($data['by_month'] as $row)
        <tr><td>{{ $row['month'] }}</td>@unless ($economicOnly)<td class="num">{{ $kg($row['generated_kg']) }}</td>@endunless
            <td class="num">{{ $kg($row['evacuated_kg']) }}</td><td class="num">{{ $money($row['net_balance']) }}</td></tr>
    @endforeach
    </tbody>
</table>

<h2>{{ __('documents.report.balance') }}</h2>
<table class="kpis">
    <tr>
        <td><div class="label">{{ __('documents.report.revenue') }}</div><div class="value">{{ $money($totals['revenue']) }}</div></td>
        <td><div class="label">{{ __('documents.report.cost') }}</div><div class="value">{{ $money($totals['cost']) }}</div></td>
        <td><div class="label">{{ __('documents.report.net_balance') }}</div><div class="value">{{ $money($totals['net_balance']) }}</div></td>
        <td><div class="label">{{ __('documents.report.hazardous_waste') }}</div><div class="value">{{ $kg($totals['hazardous_kg']) }}</div></td>
    </tr>
</table>

<p class="note">{{ __('documents.report.method') }}@if ((float) $totals['undeclared_channel_kg'] > 0){{ __('documents.report.method_undeclared', ['kg' => $kg($totals['undeclared_channel_kg'])]) }}@endif.</p>
</body>
</html>
