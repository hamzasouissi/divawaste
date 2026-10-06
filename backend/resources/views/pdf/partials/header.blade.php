<table class="head">
    <tr>
        <td style="width: 140pt;">@if ($logo)<img src="{{ $logo }}" alt="">@endif</td>
        <td>
            <h1>{{ $title }}</h1>
            <div class="meta">
                @isset($from) {{ __('documents.period', ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')]) }} · @endisset
                {{ __('documents.generated', ['date' => $generatedAt->format('d/m/Y'), 'time' => $generatedAt->format('H:i')]) }}
            </div>
        </td>
        <td class="company">
            <strong>{{ $company->legal_name }}</strong><br>
            @if ($company->tax_id) {{ __('documents.tax_id') }}{{ __('documents.colon') }}{{ $company->tax_id }}<br>@endif
            {{ trim(($company->address_line1 ?? '').' '.($company->postal_code ?? '').' '.($company->city ?? '')) }}
        </td>
    </tr>
</table>
<div class="foot">{{ $company->legal_name }} — {{ $title }} — {{ __('documents.footer') }}</div>
