{{-- The employee's signature block, 45 x 25 mm like a card (documents design §11.4). Drawn by DocumentPrinter. --}}
<div dir="rtl" style="width: 44.4mm; height: 24.4mm; border: 0.3mm solid {{ $border }}; background-color: {{ $fill }}; overflow: hidden;">
    <div style="width: 44.4mm; text-align: center; font-size: 8pt; font-weight: bold; color: #000000; padding-top: 1mm;">قرأت ووافقت</div>
    <table dir="rtl" style="width: 44.4mm; border-collapse: collapse;" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 16mm; vertical-align: top; text-align: center; padding: 1.5mm 0.5mm 0 0.5mm;">
                <img src="var:{{ $signatureKey }}" style="width: 14mm; height: 8mm;">
            </td>
            <td style="vertical-align: top; text-align: right; padding: 1.5mm 1.5mm 0 1mm;">
                <div style="font-size: 8pt; font-weight: bold; color: #000000;">{{ $name }}</div>
                <div dir="ltr" style="font-size: 5pt; color: #787878; text-align: right;">{{ $date }}</div>
            </td>
        </tr>
    </table>
</div>
