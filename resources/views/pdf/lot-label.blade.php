<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #000; }
        .label { width: 100mm; height: 50mm; padding: 3mm; position: relative; }
        .qr { position: absolute; left: 3mm; top: 6mm; width: 38mm; height: 38mm; }
        .text { position: absolute; left: 44mm; top: 3mm; width: 53mm; }
        .company { font-size: 6.5pt; text-transform: uppercase; letter-spacing: .3pt; }
        .number { font-size: 15pt; font-weight: bold; line-height: 1.1; margin-top: 1mm; }
        .type { font-size: 9.5pt; font-weight: bold; margin-top: 1.2mm; line-height: 1.15; }
        .weight { font-size: 13pt; font-weight: bold; margin-top: 1.2mm; }
        .line { font-size: 7pt; margin-top: .8mm; line-height: 1.2; }
        .hazard { display: inline-block; border: 1.2pt solid #000; padding: .4mm 1.5mm; font-size: 7.5pt; font-weight: bold; margin-top: 1mm; }
        .tag { position: absolute; left: 3mm; bottom: 1.2mm; width: 38mm; text-align: center; font-size: 5pt; font-family: DejaVu Sans Mono, monospace; }
    </style>
</head>
<body>
<div class="label">
    <img class="qr" src="{{ $qr }}" alt="">
    <div class="tag">{{ $lot->activeQrTag->tag_value }}</div>
    <div class="text">
        <div class="company">{{ \Illuminate\Support\Str::limit($company, 34, '…') }}</div>
        <div class="number">{{ $lot->lot_number }}</div>
        <div class="type">{{ \Illuminate\Support\Str::limit($lot->wasteType->name, 44, '…') }}</div>
        <div class="weight">{{ rtrim(rtrim(number_format((float) $lot->net_weight_kg, 3, ',', ' '), '0'), ',') }} kg</div>
        <div class="line">{{ $lot->site->code }} · {{ \Illuminate\Support\Str::limit($lot->zone->name, 26, '…') }}</div>
        <div class="line">{{ $lot->generated_at->timezone($timezone)->format('d/m/Y H:i') }}</div>
        @if ($lot->composition_label)
            <div class="line">{{ \Illuminate\Support\Str::limit($lot->composition_label, 40, '…') }}</div>
        @endif
        @if ($lot->is_hazardous)
            <div class="hazard">DÉCHET DANGEREUX</div>
        @endif
    </div>
</div>
</body>
</html>
