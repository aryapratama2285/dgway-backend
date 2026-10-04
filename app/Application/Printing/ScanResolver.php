<?php

declare(strict_types=1);

namespace App\Application\Printing;

/**
 * One resolver for every scanned code (QR or barcode / typed text).
 * Read-only, no side effects. Deterministic outcomes:
 *   QR dgway://v1/<entity>/<uuid>  → that entity, or SCAN_CODE_UNKNOWN
 *   text                           → exact lot number or product SKU (upper-cased);
 *                                    0 matches → SCAN_CODE_UNKNOWN, >1 type → SCAN_CODE_AMBIGUOUS
 */
final class ScanResolver
{
    private const LINKS = [
        'LOT' => ['detail' => '/api/v1/lots/%s', 'label' => '/api/v1/lots/%s/label'],
        'PRODUCT' => ['detail' => '/api/v1/products/%s'],
        'SALE' => ['detail' => '/api/v1/sales/%s', 'receipt' => '/api/v1/sales/%s/print'],
    ];

    public function __construct(private readonly PrintQueries $queries)
    {
    }

    public function resolve(string $raw): array
    {
        $parsed = IdentifierCodec::parse($raw);
        if ($parsed['kind'] === 'QR') {
            $hit = $this->queries->existsById($parsed['type'], $parsed['id']) ?? throw ScanRejected::unknown();
            $matchedBy = 'QR';
        } else {
            $hits = $this->queries->matchText($parsed['text']);
            if ($hits === []) {
                throw ScanRejected::unknown();
            }
            if (count($hits) > 1) {
                $types = array_values(array_unique(array_column($hits, 'type')));
                sort($types);
                throw ScanRejected::ambiguous($types);
            }
            $hit = $hits[0];
            $matchedBy = $hit['type'] === 'LOT' ? 'LOT_NUMBER' : 'SKU';
        }

        return [
            'type' => $hit['type'],
            'id' => $hit['id'],
            'matched_by' => $matchedBy,
            'display' => $this->queries->display($hit['type'], $hit['id']),
            'links' => array_map(fn ($p) => sprintf($p, $hit['id']), self::LINKS[$hit['type']]),
        ];
    }
}
