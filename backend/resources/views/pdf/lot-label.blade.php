<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        /* One label = one page of 100 × 50 mm. Table layout: dompdf keeps a single fixed-height table on one page. */
        @page { margin: 0; }
        html, body { margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, sans-serif; color: #000; }
        table.label { width: 100mm; height: 49mm; border-collapse: collapse; table-layout: fixed; page-break-inside: avoid; }
        td { padding: 0; vertical-align: middle; overflow: hidden; }
        td.code { width: 42mm; text-align: center; }
        td.code img { width: 36mm; height: 36mm; }
        td.text { width: 58mm; padding-right: 3mm; }
        .tag { font-size: 5pt; font-family: DejaVu Sans Mono, monospace; margin-top: .5mm; }
        .company { font-size: 6.5pt; text-transform: uppercase; }
        .number { font-size: 15pt; font-weight: bold; line-height: 1.1; margin-top: .8mm; }
        .type { font-size: 9.5pt; font-weight: bold; line-height: 1.15; margin-top: 1mm; }
        .weight { font-size: 13pt; font-weight: bold; margin-top: 1mm; }
        .line { font-size: 7pt; line-height: 1.2; margin-top: .6mm; }
        .hazard { display: inline-block; border: 1.2pt solid #000; padding: .3mm 1.5mm; font-size: 7.5pt; font-weight: bold; margin-top: 1mm; }
    </style>
</head>
<body>
<table class="label">
    <tr>
        <td class="code">
            <img src="{{ $qr }}" alt="">
            <div class="tag">{{ $lot->activeQrTag->tag_value }}</div>
        </td>
        <td class="text">
            <div class="company">{{ \Illuminate\Support\Str::limit($company, 34, '…') }}</div>
            <div class="number">{{ $lot->lot_number }}</div>
            <div class="type">{{ \Illuminate\Support\Str::limit($lot->wasteType->name, 40, '…') }}</div>
            <div class="weight">{{ rtrim(rtrim(number_format((float) $lot->net_weight_kg, 3, ',', ' '), '0'), ',') }} kg</div>
            <div class="line">{{ $lot->site->code }} · {{ \Illuminate\Support\Str::limit($lot->zone->name, 26, '…') }} · {{ $lot->generated_at->timezone($timezone)->format('d/m/Y H:i') }}</div>
            @if ($lot->composition_label)
                <div class="line">{{ \Illuminate\Support\Str::limit($lot->composition_label, 40, '…') }}</div>
            @endif
            @if ($lot->is_hazardous)
                <div class="hazard">DÉCHET DANGEREUX</div>
            @endif
        </td>
    </tr>
</table>
</body>
</html>
