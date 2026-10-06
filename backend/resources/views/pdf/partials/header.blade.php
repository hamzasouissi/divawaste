<table class="head">
    <tr>
        <td style="width: 140pt;">@if ($logo)<img src="{{ $logo }}" alt="">@endif</td>
        <td>
            <h1>{{ $title }}</h1>
            <div class="meta">
                @isset($from) Période du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }} · @endisset
                Édité le {{ $generatedAt->format('d/m/Y à H:i') }}
            </div>
        </td>
        <td class="company">
            <strong>{{ $company->legal_name }}</strong><br>
            @if ($company->tax_id) Matricule fiscal : {{ $company->tax_id }}<br>@endif
            {{ trim(($company->address_line1 ?? '').' '.($company->postal_code ?? '').' '.($company->city ?? '')) }}
        </td>
    </tr>
</table>
<div class="foot">{{ $company->legal_name }} — {{ $title }} — document généré par DivaWaste</div>
