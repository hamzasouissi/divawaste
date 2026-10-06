@php
    $party = fn (array $p) => e($p['legal_name'] ?? '').'<br>'.(($p['tax_id'] ?? null) ? 'Matricule fiscal : '.e($p['tax_id']).'<br>' : '').e($p['address'] ?? '').(($p['phone'] ?? null) ? '<br>Tél. '.e($p['phone']) : '');
    $kg = fn ($value) => $value === null ? '—' : number_format((float) $value, 3, ',', ' ').' kg';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.head')
    <style>
        .box { border: .8pt solid #1a2227; padding: 6pt 8pt; margin-bottom: 8pt; }
        .box h3 { margin: 0 0 4pt 0; font-size: 8.5pt; text-transform: uppercase; }
        .cols { width: 100%; border-collapse: separate; border-spacing: 6pt 0; margin: 0 -6pt 8pt -6pt; }
        .cols td { border: .8pt solid #1a2227; padding: 6pt 8pt; vertical-align: top; width: 33.3%; font-size: 8pt; }
        .draft { color: #b3261e; font-weight: bold; font-size: 9pt; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td><h1>Bordereau de suivi des déchets dangereux</h1>
            <div class="meta">N° {{ $payload['manifest_number'] }} · Enlèvement {{ $payload['pickup_number'] }} du {{ \Illuminate\Support\Carbon::parse($payload['pickup_date'])->format('d/m/Y') }}</div></td>
        <td class="company">@unless ($complete)<span class="draft">EN COURS DE SIGNATURE</span>@endunless</td>
    </tr>
</table>

<table class="cols">
    <tr>
        <td><strong>1. Producteur</strong><br>{!! $party($payload['producer']) !!}<br>Site : {{ $payload['producer']['site'] ?? '' }} — {{ $payload['producer']['site_address'] ?? '' }}</td>
        <td><strong>2. Transporteur</strong><br>{!! $party($payload['transporter']) !!}</td>
        <td><strong>3. Destinataire</strong><br>{!! $party($payload['receiver']) !!}</td>
    </tr>
</table>

<div class="box">
    <h3>Déchet</h3>
    Code : <strong>{{ $payload['waste_code'] }}</strong> — {{ $payload['waste_label'] }}<br>
    Désignation : {{ $payload['waste_type'] }} · Nombre de colis : {{ $payload['package_count'] }} · Poids au départ : <strong>{{ $kg($payload['declared_weight_kg']) }}</strong>
</div>

<table class="data">
    <thead><tr><th>N° de lot</th><th class="num">Poids au départ</th><th class="num">Poids reçu</th></tr></thead>
    <tbody>
    @foreach ($payload['lots'] as $lot)
        <tr><td>{{ $lot['lot_number'] }}</td><td class="num">{{ $kg($lot['weight_kg']) }}</td><td class="num">{{ $kg($lot['received_weight_kg']) }}</td></tr>
    @endforeach
    </tbody>
</table>

<h2>Signatures électroniques</h2>
<table class="data">
    <thead><tr><th>Intervenant</th><th>Signataire</th><th>Date</th><th>Empreinte du contenu signé (SHA-256)</th></tr></thead>
    <tbody>
    @foreach ($signatures as $signature)
        <tr>
            <td>{{ __('exports.signer.'.$signature->signer_party) }}</td>
            <td>{{ $signature->status === 'signed' ? trim($signature->first_name.' '.$signature->last_name) : 'En attente' }}</td>
            <td>{{ $signature->signed_at ? \Illuminate\Support\Carbon::parse($signature->signed_at, 'UTC')->timezone($timezone)->format('d/m/Y H:i') : '—' }}</td>
            <td style="font-family: DejaVu Sans Mono, monospace; font-size: 6pt;">{{ $signature->signed_payload_hash ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<p class="note">Chaque signature est apposée par un utilisateur authentifié après confirmation de son mot de passe ; la date, l'adresse IP et l'empreinte du contenu signé sont conservées.</p>
</body>
</html>
