<?php

namespace App\Modules\Waste\Support;

use App\Modules\Waste\Models\LotTag;
use App\Modules\Waste\Models\WasteLot;
use App\Modules\Waste\Models\WasteLotEvent;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * M2-02: thermal label 100 × 50 mm with the lot's unique QR code.
 * The QR carries the tag value only (26 characters): small enough to stay readable on a 35 mm square
 * printed at 203 dpi, and meaningless outside the platform. Scanning resolves it through GET /lots/scan/{tag}.
 */
final class LotLabel
{
    private const MM = 72 / 25.4;

    public const WIDTH_MM = 100;

    public const HEIGHT_MM = 50;

    /**
     * @return string PDF binary
     */
    public static function render(WasteLot $lot): string
    {
        $lot->loadMissing(['site:id,ulid,code,name', 'zone:id,ulid,code,name', 'wasteType:id,ulid,code,name', 'activeQrTag:id,waste_lot_id,tag_value,print_count']);

        $company = DB::table('companies')->where('id', $lot->company_id)->first(['legal_name', 'timezone']);

        return Pdf::loadView('pdf.lot-label', [
            'lot' => $lot,
            'company' => $company->legal_name,
            'timezone' => $company->timezone,
            'qr' => 'data:image/png;base64,'.base64_encode(self::qrPng((string) $lot->activeQrTag->tag_value)),
        ])->setPaper([0, 0, self::WIDTH_MM * self::MM, self::HEIGHT_MM * self::MM])->output();
    }

    /**
     * Counts the print and keeps it in the lot history (reprints are legitimate: damaged or lost labels).
     */
    public static function recordPrint(WasteLot $lot, int $userId): void
    {
        LotTag::query()->whereKey($lot->activeQrTag->id)->update([
            'print_count' => DB::raw('print_count + 1'),
            'last_printed_at' => now(),
        ]);
        WasteLotEvent::record($lot, 'label_printed', ['user_id' => $userId, 'payload' => ['print' => $lot->activeQrTag->print_count + 1]]);
    }

    public static function qrPng(string $value, int $pixels = 400): string
    {
        // Level Q: 25 % of the code can be damaged (dust, creases on a bale) and still be read.
        return (new Writer(new GDLibRenderer($pixels, 2)))->writeString($value, 'UTF-8', ErrorCorrectionLevel::Q());
    }
}
