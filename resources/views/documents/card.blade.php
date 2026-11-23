{{-- One signature card, 45 x 25 mm (documents design §11.4). Drawn by DocumentPrinter. --}}
<div dir="rtl" style="width: 44.4mm; height: 24.4mm; border: 0.3mm solid {{ $border }}; background-color: {{ $fill }}; overflow: hidden;">
    <table dir="rtl" style="width: 44.4mm; border-collapse: collapse;" cellpadding="0" cellspacing="0">
        <tr>
            @if ($stampKey !== null || $signatureKey !== null)
                <td style="width: 16mm; vertical-align: top; text-align: center; padding: 1.5mm 0.5mm 0 0.5mm;">
                    @if ($stampKey !== null)
                        <img src="var:{{ $stampKey }}" style="width: 14mm; height: 14mm;">
                    @endif
                    @if ($signatureKey !== null)
                        <br><img src="var:{{ $signatureKey }}" style="width: 14mm; height: 8mm;">
                    @endif
                </td>
            @endif
            <td style="vertical-align: top; text-align: right; padding: 1mm 1.5mm 0 1mm;">
                <div style="font-size: 8pt; font-weight: bold; color: #000000;">{{ $name }}</div>
                <div style="font-size: 7pt; color: #505050;">{{ $unit }}</div>
                <div dir="ltr" style="font-size: 5pt; color: #787878; text-align: right;">{{ $date }}</div>
                @if ($note !== null)
                    <div style="font-size: 5pt; color: {{ $noteColour }};">{{ $note }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>
