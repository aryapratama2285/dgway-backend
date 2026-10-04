<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Printing\IdentifierCodec;
use App\Application\Printing\PrintFormat;
use App\Application\Printing\PrintSettings;
use App\Application\Printing\ScanRejected;
use App\Domain\Shared\Exception\InvariantViolation;
use PHPUnit\Framework\TestCase;

/** A5-C pure rules: QR codec, exact formatting, print-settings validation (no database). */
final class PrintingContractTest extends TestCase
{
    public function test_qr_codec_round_trip_is_deterministic(): void
    {
        $id = '01A100DE-015E-71EE-82F3-8499E95BF950';
        $this->assertSame('dgway://lot/01a100de-015e-71ee-82f3-8499e95bf950', IdentifierCodec::qr('lot', $id));
        foreach (['dgway://lot/'.$id, 'DGWAY://LOT/'.strtolower($id), ' dgway://v1/lot/'.$id.' '] as $variant) {
            $this->assertSame(['kind' => 'QR', 'type' => 'LOT', 'id' => strtolower($id)], IdentifierCodec::parse($variant));
        }
        $this->assertSame(['kind' => 'TEXT', 'text' => 'LOT-261003-001'], IdentifierCodec::parse(' lot-261003-001 '));
        $this->assertSame(['symbology' => 'CODE128', 'value' => 'LOT-261003-001'], IdentifierCodec::barcode('LOT-261003-001'));
    }

    public function test_qr_codec_rejects_with_stable_codes(): void
    {
        $code = function (string $raw): string {
            try {
                IdentifierCodec::parse($raw);

                return 'accepted';
            } catch (ScanRejected $e) {
                return $e->errorCode();
            }
        };
        $this->assertSame('SCAN_VERSION_UNSUPPORTED', $code('dgway://v2/lot/01a100de-015e-71ee-82f3-8499e95bf950'));
        foreach (['', '   ', 'dgway://', 'dgway://lot/123', 'dgway://lot/01a100de-015e-71ee-82f3-8499e95bf950/extra', 'dgway://token/01a100de-015e-71ee-82f3-8499e95bf950', "A\nB"] as $bad) {
            $this->assertSame('SCAN_CODE_INVALID', $code($bad), json_encode($bad));
        }
    }

    public function test_exact_money_and_quantity_formatting(): void
    {
        $this->assertSame(['amount' => '1275000.0000', 'formatted' => 'Rp1.275.000'], PrintFormat::money('1275000.0000'));
        $this->assertSame('Rp0', PrintFormat::money('0.0000')['formatted']);
        $this->assertSame('Rp33.344,5', PrintFormat::money('33344.5000')['formatted']);
        $this->assertSame('Rp10,1234', PrintFormat::money('10.1234')['formatted'], 'never hides stored precision');
        $this->assertSame('-500', PrintFormat::number('-500.0000', 2));
        $this->assertSame(['value' => '1.2350', 'formatted' => '1,235', 'uom' => 'KG'], PrintFormat::quantity('1.2350', 'KG'));
        $this->assertSame('1.000.000', PrintFormat::number('1000000', 4));
        $this->assertTrue(PrintFormat::isZero('0.0000'));
        $this->assertFalse(PrintFormat::isZero('0.0001'));
    }

    public function test_print_settings_validation(): void
    {
        $s = PrintSettings::of(['business_name' => ' DG  WAY ', 'business_subtitle' => '', 'receipt_width_mm' => 58]);
        $this->assertSame(['DG WAY', null, 58], [$s->businessName, $s->businessSubtitle, $s->receiptWidthMm]);
        $this->assertSame(80, PrintSettings::of(['business_name' => 'X'])->receiptWidthMm, 'default width 80 mm');
        foreach ([['business_name' => "\u{00A0}\u{00A0}"], ['business_name' => 'X', 'receipt_width_mm' => 76], ['business_name' => str_repeat('x', 61)]] as $bad) {
            try {
                PrintSettings::of($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (InvariantViolation $e) {
                $this->assertContains($e->errorCode(), ['BUSINESS_NAME_REQUIRED', 'RECEIPT_WIDTH_INVALID', 'PRINT_FIELD_TOO_LONG']);
            }
        }
        $next = $s->with(['phone' => '0812']);
        $this->assertSame([2, '0812', 'DG WAY'], [$next->version, $next->phone, $next->businessName]);
    }
}
