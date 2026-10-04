@php $kg = fn ($value) => number_format((float) $value, 3, ',', ' '); @endphp
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8">@include('pdf.partials.head')</head>
<body>
@include('pdf.partials.header')

<p>Site : <strong>{{ $data['producer']['site'] }}</strong> ({{ $data['producer']['site_code'] }}) — {{ $data['producer']['address'] }}
    @if ($data['producer']['regulatory_identifier']) · Identifiant réglementaire : {{ $data['producer']['regulatory_identifier'] }} @endif
    · Statut : <strong>{{ $declaration->status->value === 'validated' ? 'Validée' : 'Projet' }}</strong></p>

<table class="kpis">
    <tr>
        <td><div class="label">Déchets produits</div><div class="value">{{ $kg($data['totals']['produced_kg']) }} kg</div></td>
        <td><div class="label">Dont dangereux</div><div class="value">{{ $kg($data['totals']['hazardous_produced_kg']) }} kg</div></td>
        <td><div class="label">Déchets évacués</div><div class="value">{{ $kg($data['totals']['evacuated_kg']) }} kg</div></td>
        <td><div class="label">Stock au 31/12</div><div class="value">{{ $kg($data['totals']['stock_at_year_end_kg']) }} kg</div></td>
    </tr>
</table>

<h2>Déchets produits dans l'année</h2>
<table class="data">
    <thead><tr><th>Code déchet</th><th>Désignation</th><th>Dangereux</th><th class="num">Lots</th><th class="num">Quantité (kg)</th></tr></thead>
    <tbody>
    @forelse ($data['produced'] as $row)
        <tr><td>{{ $row['regulatory_code'] ?? '—' }}</td><td>{{ $row['waste_type'] }}</td><td>{{ $row['is_hazardous'] ? 'Oui' : 'Non' }}</td>
            <td class="num">{{ $row['lots'] }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
    @empty
        <tr><td colspan="5">Aucun déchet produit sur l'année.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Déchets évacués, par destinataire et filière</h2>
<table class="data">
    <thead><tr><th>Code déchet</th><th>Désignation</th><th>Dangereux</th><th>Destinataire</th><th>Filière</th><th class="num">Quantité (kg)</th></tr></thead>
    <tbody>
    @forelse ($data['evacuated'] as $row)
        <tr><td>{{ $row['regulatory_code'] ?? '—' }}</td><td>{{ $row['waste_type'] }}</td><td>{{ $row['is_hazardous'] ? 'Oui' : 'Non' }}</td>
            <td>{{ $row['provider'] }}</td><td>{{ $row['channel'] ? __('exports.channel.'.$row['channel']) : 'Non déclarée' }}</td><td class="num">{{ $kg($row['kg']) }}</td></tr>
    @empty
        <tr><td colspan="6">Aucun déchet évacué sur l'année.</td></tr>
    @endforelse
    </tbody>
</table>

<p class="note">Déclaration calculée automatiquement à partir des lots et des enlèvements enregistrés ({{ $declaration->computed_at?->format('d/m/Y') }}).
    Référence : {{ $declaration->submission_reference ?? 'attribuée à la validation' }}.</p>
</body>
</html>
