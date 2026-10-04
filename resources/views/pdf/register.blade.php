<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8">@include('pdf.partials.head')</head>
<body>
@include('pdf.partials.header')
<table class="data">
    <thead>
    <tr>
        @foreach ($headings as $index => $heading)
            <th class="{{ in_array($index, [7, 13], true) ? 'num' : '' }}">{{ $heading }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>
            @foreach ($row as $index => $cell)
                <td class="{{ in_array($index, [7, 13], true) ? 'num' : '' }}">{{ is_float($cell) ? number_format($cell, 3, ',', ' ') : $cell }}</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($headings) }}">Aucun lot sur la période.</td></tr>
    @endforelse
    </tbody>
</table>
<p class="note">{{ count($rows) }} lot(s) — poids total : {{ number_format(array_sum(array_column($rows, 7)), 3, ',', ' ') }} kg.
    Registre généré automatiquement à partir des lots enregistrés ; aucune ligne n'est saisie manuellement.</p>
</body>
</html>
