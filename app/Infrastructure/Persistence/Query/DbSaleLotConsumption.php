<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Sales\SaleLotConsumption;
use App\Domain\Shared\Identity\Id;
use Illuminate\Database\ConnectionInterface;

final class DbSaleLotConsumption implements SaleLotConsumption
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function ofSaleLine(Id $saleLineId): array
    {
        $rows = $this->db->select(<<<'SQL'
            WITH sold AS (
                SELECT COALESCE(m.lot_id, dgway_legacy_lot(m.product_id, m.node_id)) AS lot_id, min(m.sequence) AS first_seq,
                       sum(m.quantity) FILTER (WHERE NOT EXISTS (SELECT 1 FROM stock_movements r WHERE r.reverses_movement_id = m.id)) AS consumed
                  FROM stock_movements m
                 WHERE m.source_document_type = 'sale' AND m.source_line_id = ? AND m.movement_type = 'SALE'
                 GROUP BY 1
            ), returned AS (
                SELECT m.lot_id, sum(m.quantity) AS qty
                  FROM customer_return_lines crl
                  JOIN stock_movements m ON m.source_document_type = 'customer_return' AND m.source_line_id = crl.id AND m.movement_type = 'CUSTOMER_RETURN'
                 WHERE crl.sale_line_id = ?
                 GROUP BY m.lot_id
            )
            SELECT s.lot_id, l.number, COALESCE(s.consumed, 0)::numeric(18,4) AS consumed, COALESCE(r.qty, 0)::numeric(18,4) AS returned
              FROM sold s JOIN inventory_lots l ON l.id = s.lot_id LEFT JOIN returned r ON r.lot_id = s.lot_id
             ORDER BY s.first_seq
        SQL, [$saleLineId->value, $saleLineId->value]);

        return array_map(fn ($r) => ['lot_id' => $r->lot_id, 'lot_number' => $r->number, 'consumed' => (string) $r->consumed, 'returned' => (string) $r->returned], $rows);
    }
}
