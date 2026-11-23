{{--
    طلب شراء: legacy's hand-made purchase request, ported (documents design §8.1). Drawn by TemplateHtml
    with $template, $fields (the schema), $values (as they print; an empty one is null), $system (the
    system.* values) and $recipient. Every value is escaped with {{ }}. Inline styles only: the page's
    stylesheet is TemplateHtml::STYLESHEET. Legacy's empty signature boxes are not ported: the cards
    replace them.
--}}
@php
    $schema = collect($fields)->keyBy('key');
    $label = static fn (string $key, string $fallback): string => (string) ($schema[$key]['label'] ?? $fallback);
    $columns = $schema['items']['columns'] ?? [];
    $plain = 'border: none; padding: 0;';
    $caption = 'font-weight: bold; font-size: 10pt; color: #1A3A5C;';
    $box = 'border: 0.3mm solid #E0E0E0; background-color: #FAFAFA; padding: 1mm 2mm;';
    $meta = 'border: none; padding: 0.5mm 1mm; font-size: 9pt;';
    $metaLabel = $meta.' font-weight: bold; color: #1A3A5C; width: 22mm;';
    $amount = $values['total_amount'] ?? null;
    $delivery = $values['delivery_date'] ?? null;
    $items = $values['items'] ?? null;
@endphp
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="{{ $plain }}"></td>
        <td style="{{ $plain }} width: 70mm; vertical-align: top;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr><td style="{{ $metaLabel }}">رقم الصادر:</td><td style="{{ $meta }}">{{ $system['system.export_number'] }}</td></tr>
                @if ($system['system.import_number'] !== '')
                    <tr><td style="{{ $metaLabel }}">رقم الوارد:</td><td style="{{ $meta }}">{{ $system['system.import_number'] }}</td></tr>
                @endif
                <tr><td style="{{ $metaLabel }}">المرجع:</td><td style="{{ $meta }}">{{ $system['system.reference'] }}</td></tr>
                <tr><td style="{{ $metaLabel }}">التاريخ:</td><td style="{{ $meta }}">{{ $system['system.date'] }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div style="text-align: center; font-size: 14pt; font-weight: bold; color: #1A3A5C; padding: 6mm 0 2mm 0;">{{ $template->name }}</div>
<div style="font-weight: bold; color: #2F3A45; margin-bottom: 6mm;">{{ $system['system.creator_unit'] }}</div>

@if (($values['vendor_name'] ?? null) !== null)
    <div style="margin-bottom: 3mm;">
        <div style="{{ $caption }}">{{ $label('vendor_name', 'اسم المورد') }}</div>
        <div style="{{ $box }}">{{ $values['vendor_name'] }}</div>
    </div>
@endif

@if ($amount !== null || $delivery !== null)
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 3mm;">
        <tr>
            <td style="{{ $plain }} width: 50%; vertical-align: top; padding-left: 2mm;">
                @if ($amount !== null)
                    <div style="{{ $caption }}">{{ $label('total_amount', 'المبلغ الإجمالي') }}</div>
                    <div style="{{ $box }}">{{ $amount }}@if (($values['currency'] ?? null) !== null) {{ $values['currency'] }}@endif</div>
                @endif
            </td>
            <td style="{{ $plain }} width: 50%; vertical-align: top;">
                @if ($delivery !== null)
                    <div style="{{ $caption }}">{{ $label('delivery_date', 'تاريخ التسليم المتوقع') }}</div>
                    <div style="{{ $box }}">{{ $delivery }}</div>
                @endif
            </td>
        </tr>
    </table>
@endif

@if (is_array($items))
    <div style="margin-bottom: 3mm;">
        <div style="{{ $caption }}">{{ $label('items', 'البنود') }}</div>
        <table>
            <thead>
                <tr><th style="width: 10mm;">#</th>@foreach ($columns as $column)<th>{{ $column['label'] }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @foreach ($items as $index => $row)
                    <tr><td style="text-align: center;">{{ $index + 1 }}</td>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if (($values['justification'] ?? null) !== null)
    <div style="margin-bottom: 3mm;">
        <div style="{{ $caption }}">{{ $label('justification', 'مبررات الشراء') }}</div>
        <div style="{{ $box }}">@foreach (preg_split('/\r\n|\r|\n/', $values['justification']) as $line)@if (! $loop->first)<br>@endif{{ $line }}@endforeach</div>
    </div>
@endif
