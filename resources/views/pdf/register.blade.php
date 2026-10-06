@use('App\Modules\Common\Support\DocumentLanguage', 'Doc')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
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
                <td class="{{ in_array($index, [7, 13], true) ? 'num' : '' }}">{{ is_float($cell) ? Doc::number($cell) : $cell }}</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($headings) }}">{{ __('documents.register.empty') }}</td></tr>
    @endforelse
    </tbody>
</table>
<p class="note">{{ __('documents.register.summary', ['count' => count($rows), 'kg' => Doc::number(array_sum(array_column($rows, 7)))]) }}
    {{ __('documents.register.note') }}</p>
</body>
</html>
