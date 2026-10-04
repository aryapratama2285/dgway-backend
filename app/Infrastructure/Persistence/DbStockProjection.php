<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Inventory\StockProjection;
use Illuminate\Database\ConnectionInterface;

final class DbStockProjection implements StockProjection
{
    private const LEDGER_SUMS = <<<'SQL'
        SELECT product_id, node_id, bucket, min(uom_code) AS uom_code, sum(signed_quantity)::numeric(18,4) AS quantity
          FROM stock_movements GROUP BY product_id, node_id, bucket
        SQL;

    /** Ledger sums per lot position; pre-A5-B rows (lot_id NULL) belong to their LEGACY lot. */
    private const LOT_LEDGER_SUMS = <<<'SQL'
        SELECT COALESCE(lot_id, dgway_legacy_lot(product_id, node_id)) AS lot_id, node_id, bucket, product_id,
               min(uom_code) AS uom_code, sum(signed_quantity)::numeric(18,4) AS quantity
          FROM stock_movements GROUP BY 1, 2, 3, 4
        SQL;

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function verify(): array
    {
        $rows = $this->db->select(
            'SELECT COALESCE(b.product_id, l.product_id) AS product_id, COALESCE(b.node_id, l.node_id) AS node_id,
                    COALESCE(b.bucket, l.bucket) AS bucket,
                    COALESCE(b.quantity, 0)::numeric(18,4) AS projected, COALESCE(l.quantity, 0)::numeric(18,4) AS ledger
               FROM stock_balances b
               FULL OUTER JOIN ('.self::LEDGER_SUMS.') l
                 ON l.product_id = b.product_id AND l.node_id = b.node_id AND l.bucket = b.bucket
              WHERE COALESCE(b.quantity, 0) <> COALESCE(l.quantity, 0)',
        );

        $lots = $this->db->select(
            'SELECT COALESCE(b.product_id, l.product_id) AS product_id, COALESCE(b.node_id, l.node_id) AS node_id,
                    COALESCE(b.bucket, l.bucket) AS bucket, COALESCE(b.lot_id, l.lot_id) AS lot_id,
                    COALESCE(b.quantity, 0)::numeric(18,4) AS projected, COALESCE(l.quantity, 0)::numeric(18,4) AS ledger
               FROM stock_lot_balances b
               FULL OUTER JOIN ('.self::LOT_LEDGER_SUMS.') l ON l.lot_id = b.lot_id AND l.node_id = b.node_id AND l.bucket = b.bucket
              WHERE COALESCE(b.quantity, 0) <> COALESCE(l.quantity, 0)',
        );

        return array_merge(array_map(fn ($r) => [
            'product_id' => $r->product_id, 'node_id' => $r->node_id, 'bucket' => $r->bucket,
            'projected' => (string) $r->projected, 'ledger' => (string) $r->ledger,
        ], $rows), array_map(fn ($r) => [
            'product_id' => $r->product_id, 'node_id' => $r->node_id, 'bucket' => $r->bucket, 'lot_id' => $r->lot_id,
            'projected' => (string) $r->projected, 'ledger' => (string) $r->ledger,
        ], $lots));
    }

    public function rebuild(): int
    {
        return $this->db->transaction(function (): int {
            // Block concurrent postings (they need row locks on stock_balances)
            // for the duration of the rebuild.
            // Same lock order as postings: stock positions, lot positions, then the ledger.
            $this->db->statement('LOCK TABLE stock_balances IN EXCLUSIVE MODE');
            $this->db->statement('LOCK TABLE stock_lot_balances IN EXCLUSIVE MODE');
            $this->db->statement('LOCK TABLE stock_movements IN SHARE MODE');
            $this->db->statement('DELETE FROM stock_balances');
            $this->db->statement('DELETE FROM stock_lot_balances');

            $n = $this->db->affectingStatement(
                'INSERT INTO stock_balances (product_id, node_id, bucket, uom_code, quantity, updated_at)
                 SELECT product_id, node_id, bucket, uom_code, quantity, now() FROM ('.self::LEDGER_SUMS.') l',
            );
            $this->db->affectingStatement(
                'INSERT INTO stock_lot_balances (lot_id, node_id, bucket, product_id, uom_code, quantity, updated_at)
                 SELECT lot_id, node_id, bucket, product_id, uom_code, quantity, now() FROM ('.self::LOT_LEDGER_SUMS.') l',
            );

            return $n;
        });
    }
}
