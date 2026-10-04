<?php

declare(strict_types=1);

namespace App\Infrastructure\Printing;

use App\Application\Printing\PrintFormat;
use App\Application\Printing\PrintQueries;
use App\Domain\Shared\Exception\EntityNotFound;
use Illuminate\Database\ConnectionInterface;

/** SQL read side of printable documents and scan resolution (fixed number of statements per call). */
final class DbPrintQueries implements PrintQueries
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function sale(string $saleId): array
    {
        $this->uuid('Sale', $saleId);
        $sale = $this->db->selectOne(<<<'SQL'
            SELECT s.*, c.code AS customer_code, c.name AS customer_name, n.code AS node_code, n.name AS node_name,
                   COALESCE(s.confirmed_by, s.created_by) AS operator_id, u.name AS operator_name
              FROM sales s JOIN nodes n ON n.id = s.node_id
              LEFT JOIN business_partners c ON c.id = s.customer_id
              LEFT JOIN users u ON u.id = COALESCE(s.confirmed_by, s.created_by)
             WHERE s.id = ?
        SQL, [$saleId]) ?? throw EntityNotFound::of('Sale', $saleId);

        return [
            'sale' => $sale,
            'lines' => $this->db->select('SELECT l.*, p.sku, p.name AS product_name FROM sale_lines l JOIN products p ON p.id = l.product_id WHERE l.sale_id = ? ORDER BY l.line_no', [$saleId]),
            'payments' => $this->db->select('SELECT p.*, r.id AS reversal_id FROM payments p LEFT JOIN payment_reversals r ON r.payment_id = p.id WHERE p.sale_id = ? ORDER BY p.paid_at, p.number', [$saleId]),
            'returns' => $this->db->select('SELECT * FROM customer_returns WHERE sale_id = ? ORDER BY returned_at, number', [$saleId]),
        ];
    }

    public function purchaseOrder(string $orderId): array
    {
        $this->uuid('PurchaseOrder', $orderId);
        $order = $this->db->selectOne(<<<'SQL'
            SELECT o.*, s.code AS supplier_code, s.name AS supplier_name, n.code AS node_code, n.name AS node_name,
                   o.created_by AS operator_id, u.name AS operator_name,
                   (SELECT COALESCE(sum(line_total), 0)::numeric(19,4) FROM purchase_order_lines WHERE purchase_order_id = o.id) AS total_amount
              FROM purchase_orders o JOIN business_partners s ON s.id = o.supplier_id JOIN nodes n ON n.id = o.node_id
              LEFT JOIN users u ON u.id = o.created_by
             WHERE o.id = ?
        SQL, [$orderId]) ?? throw EntityNotFound::of('PurchaseOrder', $orderId);

        return ['order' => $order, 'lines' => $this->db->select(
            'SELECT l.*, p.sku, p.name AS product_name FROM purchase_order_lines l JOIN products p ON p.id = l.product_id WHERE l.purchase_order_id = ? ORDER BY l.line_no', [$orderId])];
    }

    public function goodsReceipt(string $receiptId): array
    {
        $this->uuid('GoodsReceipt', $receiptId);
        $receipt = $this->db->selectOne(<<<'SQL'
            SELECT g.*, s.code AS supplier_code, s.name AS supplier_name, n.code AS node_code, n.name AS node_name, po.number AS purchase_order_number,
                   COALESCE(g.posted_by, g.created_by) AS operator_id, u.name AS operator_name,
                   (SELECT COALESCE(sum(line_cost), 0)::numeric(19,4) FROM goods_receipt_lines WHERE goods_receipt_id = g.id) AS total_cost
              FROM goods_receipts g JOIN business_partners s ON s.id = g.supplier_id JOIN nodes n ON n.id = g.node_id
              LEFT JOIN purchase_orders po ON po.id = g.purchase_order_id
              LEFT JOIN users u ON u.id = COALESCE(g.posted_by, g.created_by)
             WHERE g.id = ?
        SQL, [$receiptId]) ?? throw EntityNotFound::of('GoodsReceipt', $receiptId);

        return [
            'receipt' => $receipt,
            'lines' => $this->db->select('SELECT l.*, p.sku, p.name AS product_name FROM goods_receipt_lines l JOIN products p ON p.id = l.product_id WHERE l.goods_receipt_id = ? ORDER BY l.line_no', [$receiptId]),
            'dispositions' => $this->db->select('SELECT d.*, tp.sku AS target_sku FROM goods_receipt_dispositions d JOIN goods_receipt_lines l ON l.id = d.goods_receipt_line_id
                LEFT JOIN products tp ON tp.id = d.target_product_id WHERE l.goods_receipt_id = ? ORDER BY l.line_no, d.created_at, d.id', [$receiptId]),
            'lots' => $this->db->select("SELECT id, number, goods_receipt_line_id FROM inventory_lots WHERE goods_receipt_id = ? AND origin = 'RECEIPT'", [$receiptId]),
        ];
    }

    public function lot(string $lotIdOrNumber): object
    {
        $byId = preg_match(self::UUID, $lotIdOrNumber) === 1;

        return $this->db->selectOne(sprintf(<<<'SQL'
            SELECT l.*, p.sku, p.name AS product_name, n.code AS node_code, s.code AS supplier_code, s.name AS supplier_name,
                   g.number AS goods_receipt_number, par.number AS parent_number
              FROM inventory_lots l JOIN products p ON p.id = l.product_id JOIN nodes n ON n.id = l.node_id
              LEFT JOIN business_partners s ON s.id = l.supplier_id
              LEFT JOIN goods_receipts g ON g.id = l.goods_receipt_id
              LEFT JOIN inventory_lots par ON par.id = l.parent_lot_id
             WHERE %s = ?
        SQL, $byId ? 'l.id' : 'l.number'), [$byId ? $lotIdOrNumber : strtoupper($lotIdOrNumber)]) ?? throw EntityNotFound::of('InventoryLot', $lotIdOrNumber);
    }

    public function existsById(string $type, string $id): ?array
    {
        $table = ['LOT' => 'inventory_lots', 'PRODUCT' => 'products', 'SALE' => 'sales'][$type];

        return $this->db->table($table)->where('id', $id)->exists() ? ['type' => $type, 'id' => $id] : null;
    }

    public function matchText(string $text): array
    {
        return array_map(fn ($r) => ['type' => $r->type, 'id' => $r->id], $this->db->select(<<<'SQL'
            SELECT 'LOT' AS type, id FROM inventory_lots WHERE number = ?
            UNION ALL
            SELECT 'PRODUCT', id FROM products WHERE sku = ?
            ORDER BY 1
        SQL, [$text, $text]));
    }

    public function display(string $type, string $id): array
    {
        return match ($type) {
            'LOT' => (function () use ($id) {
                $r = $this->db->selectOne(<<<'SQL'
                    SELECT l.number, l.origin, l.uom_code, p.sku, p.name,
                           COALESCE((SELECT sum(quantity) FROM stock_lot_balances WHERE lot_id = l.id AND bucket = 'AVAILABLE'), 0)::numeric(18,4) AS available,
                           COALESCE((SELECT sum(quantity) FROM stock_lot_balances WHERE lot_id = l.id), 0)::numeric(18,4) AS on_hand
                      FROM inventory_lots l JOIN products p ON p.id = l.product_id WHERE l.id = ?
                SQL, [$id]);

                return ['title' => $r->number, 'subtitle' => "{$r->sku} · {$r->name}", 'lot_number' => $r->number, 'origin' => $r->origin,
                    'sku' => $r->sku, 'product_name' => $r->name, 'status' => PrintFormat::isZero((string) $r->on_hand) ? 'DEPLETED' : 'ACTIVE',
                    'available' => ['value' => (string) $r->available, 'uom' => $r->uom_code]];
            })(),
            'PRODUCT' => (function () use ($id) {
                $r = $this->db->selectOne('SELECT sku, name, base_uom_code, is_active FROM products WHERE id = ?', [$id]);

                return ['title' => $r->sku, 'subtitle' => $r->name, 'sku' => $r->sku, 'name' => $r->name, 'base_uom' => $r->base_uom_code, 'is_active' => (bool) $r->is_active];
            })(),
            'SALE' => (function () use ($id) {
                $r = $this->db->selectOne('SELECT number, status, business_date FROM sales WHERE id = ?', [$id]);

                return ['title' => $r->number, 'subtitle' => (string) $r->business_date, 'number' => $r->number, 'status' => $r->status, 'business_date' => (string) $r->business_date];
            })(),
        };
    }

    private function uuid(string $entity, string $id): void
    {
        preg_match(self::UUID, $id) === 1 || throw EntityNotFound::of($entity, $id);
    }
}
