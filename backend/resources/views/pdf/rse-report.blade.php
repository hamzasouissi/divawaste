@php
    $kg = fn ($value) => number_format((float) $value, 0, ',', ' ').' kg';
    $t = fn ($value) => number_format((float) $value / 1000, 2, ',', ' ').' t';
    $pct = fn ($value) => $value === null ? '—' : number_format((float) $value, 1, ',', ' ').' %';
    $money = fn ($value) => number_format((float) $value, 3, ',', ' ').' '.$company->currency_code;
    $totals = $data['totals'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8">@include('pdf.partials.head')</head>
<body>
@include('pdf.partials.header')

@unless ($economicOnly)
    <table class="kpis">
        <tr>
            <td><div class="label">Déchets générés</div><div class="value">{{ $t($totals['generated_kg']) }}</div></td>
            <td><div class="label">Taux de valorisation</div><div class="value">{{ $pct($totals['recovery_rate_pct']) }}</div></td>
            <td><div class="label">Part enfouie</div><div class="value">{{ $pct($totals['landfill_share_pct']) }}</div></td>
            <td><div class="label">kg de déchets / pièce</div><div class="value">{{ $totals['kg_per_piece'] === null ? '—' : number_format((float) $totals['kg_per_piece'], 4, ',', ' ') }}</div></td>
        </tr>
    </table>

    <h2>Tonnage par type de déchet</h2>
    <table class="data">
        <thead><tr><th>Type</th><th>Dangereux</th><th class="num">Lots</th><th class="num">Quantité</th></tr></thead>
        <tbody>
        @forelse ($data['by_waste_type'] as $row)
            <tr><td>{{ $row['name'] }}</td><td>{{ $row['is_hazardous'] ? 'Oui' : 'Non' }}</td><td class="num">{{ $row['lots'] }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
        @empty
            <tr><td colspan="4">Aucun déchet généré sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Tonnage par filière</h2>
    <table class="data">
        <thead><tr><th>Filière</th><th>Valorisation</th><th class="num">Quantité évacuée</th></tr></thead>
        <tbody>
        @forelse ($data['by_channel'] as $row)
            <tr><td>{{ $row['channel'] === null ? 'Filière non déclarée par le prestataire' : __('exports.channel.'.$row['channel']) }}</td>
                <td>{{ $row['channel'] === null ? '—' : ($row['is_valorization'] ? 'Oui' : 'Non') }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
        @empty
            <tr><td colspan="3">Aucun déchet évacué sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Tonnage par site</h2>
    <table class="data">
        <thead><tr><th>Site</th><th class="num">Lots</th><th class="num">Quantité</th></tr></thead>
        <tbody>
        @foreach ($data['by_site'] as $row)
            <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['lots'] }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endunless

<h2>{{ $economicOnly ? 'Bilan économique par mois' : 'Évolution mensuelle' }}</h2>
<table class="data">
    <thead><tr><th>Mois</th>@unless ($economicOnly)<th class="num">Généré</th>@endunless<th class="num">Évacué</th><th class="num">Bilan net</th></tr></thead>
    <tbody>
    @foreach ($data['by_month'] as $row)
        <tr><td>{{ $row['month'] }}</td>@unless ($economicOnly)<td class="num">{{ $kg($row['generated_kg']) }}</td>@endunless
            <td class="num">{{ $kg($row['evacuated_kg']) }}</td><td class="num">{{ $money($row['net_balance']) }}</td></tr>
    @endforeach
    </tbody>
</table>

<h2>Bilan économique</h2>
<table class="kpis">
    <tr>
        <td><div class="label">Recettes de valorisation</div><div class="value">{{ $money($totals['revenue']) }}</div></td>
        <td><div class="label">Coûts d'élimination</div><div class="value">{{ $money($totals['cost']) }}</div></td>
        <td><div class="label">Bilan net</div><div class="value">{{ $money($totals['net_balance']) }}</div></td>
        <td><div class="label">Déchets dangereux</div><div class="value">{{ $kg($totals['hazardous_kg']) }}</div></td>
    </tr>
</table>

<p class="note">Méthode : les déchets générés sont les lots créés sur la période (hors lots issus d'une scission ou d'un regroupement).
    Le taux de valorisation et la part enfouie sont calculés sur les quantités évacuées dont la filière a été déclarée par le prestataire
    @if ((float) $totals['undeclared_channel_kg'] > 0) ; {{ $kg($totals['undeclared_channel_kg']) }} évacués n'ont pas encore de filière déclarée @endif.</p>
</body>
</html>
