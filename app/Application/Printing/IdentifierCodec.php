<?php

declare(strict_types=1);

namespace App\Application\Printing;

/**
 * QR / barcode payload contract (docs/QR_BARCODE_CONTRACT.md).
 *
 *   QR (canonical, contract version 1)   dgway://<entity>/<uuid>     entity ∈ lot | product | sale, uuid lower-case
 *   QR (explicit version, also v1)       dgway://v1/<entity>/<uuid>  accepted as an alias, never emitted
 *   future versions                      dgway://v<N>/…  (N ≥ 2)     → SCAN_VERSION_UNSUPPORTED until supported
 *   barcode (Code 128)                   the human code: lot number (LOT-YYMMDD-NNN) or product SKU
 *
 * A payload carries only a stable public identifier: no token, price,
 * quantity, customer, supplier or credit data. parse() never touches the
 * database and has no side effects.
 */
final class IdentifierCodec
{
    public const CONTRACT_VERSION = 1;

    public const ENTITIES = ['lot' => 'LOT', 'product' => 'PRODUCT', 'sale' => 'SALE'];

    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public static function qr(string $entity, string $uuid): string
    {
        return 'dgway://'.$entity.'/'.strtolower($uuid);
    }

    /** @return array{symbology: string, value: string} */
    public static function barcode(string $humanCode): array
    {
        return ['symbology' => 'CODE128', 'value' => $humanCode];
    }

    /**
     * @return array{kind: 'QR', type: string, id: string}|array{kind: 'TEXT', text: string}
     *
     * @throws ScanRejected
     */
    public static function parse(string $raw): array
    {
        $code = trim($raw);
        if ($code === '' || mb_strlen($code) > 128 || preg_match('/[\x00-\x1F\x7F]/u', $code) === 1) {
            throw ScanRejected::invalid();
        }
        if (preg_match('#^dgway://#i', $code) !== 1) {
            return ['kind' => 'TEXT', 'text' => mb_strtoupper($code)];
        }
        $path = substr($code, strlen('dgway://'));
        if (preg_match('#^v(\d+)/(.*)$#i', $path, $v) === 1) {
            if ((int) $v[1] !== self::CONTRACT_VERSION) {
                throw ScanRejected::unsupportedVersion();
            }
            $path = $v[2];
        }
        if (preg_match('#^([a-z]+)/('.self::UUID.')$#i', $path, $m) !== 1 || ! isset(self::ENTITIES[strtolower($m[1])])) {
            throw ScanRejected::invalid();
        }

        return ['kind' => 'QR', 'type' => self::ENTITIES[strtolower($m[1])], 'id' => strtolower($m[2])];
    }
}
