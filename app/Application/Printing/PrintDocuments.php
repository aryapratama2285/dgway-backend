<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Domain\Shared\Time\Clock;

/**
 * Device-neutral print documents (A5-C). The backend supplies WHAT to print
 * — business identity, exact amounts already formatted, stable identifiers
 * and QR/barcode payloads — never HOW (no ESC/POS bytes, no fonts, no
 * printer model). Envelope:
 *
 *   { schema_version, document_type, generated_at, business{…}, layout{…}, document{…} }
 *
 * schema_version changes only on a breaking change; adding fields keeps 1.
 */
final class PrintDocuments
{
    public const SCHEMA_VERSION = 1;

    private const METHOD_LABELS = ['CASH' => 'Tunai', 'BANK_TRANSFER' => 'Transfer Bank', 'QRIS' => 'QRIS', 'OTHER' => 'Lainnya'];

    private const NOTICES = [
        'DRAFT' => 'BELUM FINAL — BUKAN BUKTI PEMBAYARAN',
        'CANCELLED' => 'DIBATALKAN',
        'REVERSED' => 'TRANSAKSI DIBATALKAN (REVERSAL)',
    ];

    public function __construct(
        private readonly PrintQueries $queries,
        private readonly PrintSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    public function receipt(string $saleId, ?int $widthMm = null): array
    {
        $d = $this->queries->sale($saleId);
        $s = $d['sale'];
        $cur = $s->currency;
        $m = fn ($v) => PrintFormat::money((string) $v, $cur);
        $walkIn = $s->customer_id === null;

        return $this->envelope('RECEIPT', [
            'sale_id' => $s->id,
            'number' => $s->number,
            'status' => $s->status,
            'is_final' => in_array($s->status, ['CONFIRMED', 'COMPLETED'], true),
            'notice' => isset(self::NOTICES[$s->status]) ? ['code' => $s->status, 'text' => self::NOTICES[$s->status]] : null,
            'business_date' => (string) $s->business_date,
            'transaction_at' => self::atom($s->confirmed_at ?? $s->created_at),
            'reversed_at' => self::atom($s->reversed_at),
            'currency' => $cur,
            'is_walk_in' => $walkIn,
            'customer' => $walkIn ? null : ['code' => $s->customer_code, 'name' => $s->customer_name],
            'operator' => $s->operator_id === null ? null : ['id' => $s->operator_id, 'name' => $s->operator_name],
            'node' => ['code' => $s->node_code, 'name' => $s->node_name],
            'lines' => array_map(fn ($l) => [
                'line_no' => (int) $l->line_no,
                'sku' => $l->sku,
                'name' => $l->product_name,
                'quantity' => PrintFormat::quantity((string) $l->quantity, $l->uom_code),
                'unit_price' => $m($l->unit_price),
                'discount' => PrintFormat::isZero((string) $l->discount_amount) ? null : $m($l->discount_amount),
                'line_total' => $m($l->line_total),
                'returned_quantity' => PrintFormat::isZero((string) $l->returned_quantity) ? null : PrintFormat::quantity((string) $l->returned_quantity, $l->uom_code),
            ], $d['lines']),
            'totals' => [
                'subtotal' => $m($s->subtotal),
                'discount_total' => $m($s->discount_total),
                'grand_total' => $m($s->grand_total),
                'total_paid' => $m($s->paid_amount),
                'credited' => $m($s->credited_amount),
                'outstanding' => $m($s->outstanding),
            ],
            'payments' => array_map(fn ($p) => [
                'number' => $p->number,
                'method' => $p->method,
                'method_label' => self::METHOD_LABELS[$p->method] ?? $p->method,
                'amount' => $m($p->amount),
                'paid_at' => self::atom($p->paid_at),
                'reference' => $p->reference,
                'reversed' => $p->reversal_id !== null,
            ], $d['payments']),
            // only for a customer on credit terms: due date + what is still owed
            'payment_terms' => $walkIn || (int) ($s->payment_term_days ?? 0) === 0 ? null
                : ['term_days' => (int) $s->payment_term_days, 'due_date' => $s->due_date === null ? null : (string) $s->due_date],
            'returns' => array_map(fn ($r) => ['number' => $r->number, 'business_date' => (string) $r->business_date,
                'total_value' => $m($r->total_value), 'credit' => $m($r->credit_amount), 'refund' => $m($r->refund_amount)], $d['returns']),
            'reference' => ['document_number' => $s->number, 'qr_payload' => IdentifierCodec::qr('sale', $s->id)],
        ], $widthMm);
    }

    public function purchaseOrder(string $orderId): array
    {
        $d = $this->queries->purchaseOrder($orderId);
        $o = $d['order'];
        $m = fn ($v) => PrintFormat::money((string) $v, $o->currency);

        return $this->envelope('PURCHASE_ORDER', [
            'purchase_order_id' => $o->id, 'number' => $o->number, 'status' => $o->status,
            'notice' => $o->status === 'CANCELLED' ? ['code' => 'CANCELLED', 'text' => self::NOTICES['CANCELLED']] : ($o->status === 'DRAFT' ? ['code' => 'DRAFT', 'text' => 'DRAFT — BELUM DIKONFIRMASI'] : null),
            'business_date' => (string) $o->business_date, 'currency' => $o->currency,
            'supplier' => ['code' => $o->supplier_code, 'name' => $o->supplier_name],
            'node' => ['code' => $o->node_code, 'name' => $o->node_name],
            'operator' => $o->operator_id === null ? null : ['id' => $o->operator_id, 'name' => $o->operator_name],
            'lines' => array_map(fn ($l) => ['line_no' => (int) $l->line_no, 'sku' => $l->sku, 'name' => $l->product_name,
                'ordered_quantity' => PrintFormat::quantity((string) $l->ordered_quantity, $l->uom_code),
                'received_quantity' => PrintFormat::quantity((string) $l->received_quantity, $l->uom_code),
                'unit_cost' => $m($l->unit_cost), 'line_total' => $m($l->line_total)], $d['lines']),
            'total_amount' => $m($o->total_amount),
            'notes' => $o->notes,
        ]);
    }

    public function goodsReceipt(string $receiptId): array
    {
        $d = $this->queries->goodsReceipt($receiptId);
        $g = $d['receipt'];
        $m = fn ($v) => PrintFormat::money((string) $v, $g->currency);
        $disp = [];
        foreach ($d['dispositions'] as $x) {
            $disp[$x->goods_receipt_line_id][] = $x;
        }
        $lots = [];
        foreach ($d['lots'] as $l) {
            $lots[$l->goods_receipt_line_id] = $l;
        }

        return $this->envelope('GOODS_RECEIPT', [
            'goods_receipt_id' => $g->id, 'number' => $g->number, 'status' => $g->status,
            'notice' => isset(self::NOTICES[$g->status]) ? ['code' => $g->status, 'text' => $g->status === 'DRAFT' ? 'DRAFT — BELUM DIPOSTING' : self::NOTICES[$g->status]] : null,
            'business_date' => (string) $g->business_date, 'received_at' => self::atom($g->received_at), 'currency' => $g->currency,
            'supplier' => ['code' => $g->supplier_code, 'name' => $g->supplier_name],
            'node' => ['code' => $g->node_code, 'name' => $g->node_name],
            'purchase_order_number' => $g->purchase_order_number,
            'operator' => $g->operator_id === null ? null : ['id' => $g->operator_id, 'name' => $g->operator_name],
            'lines' => array_map(fn ($l) => [
                'line_no' => (int) $l->line_no, 'sku' => $l->sku, 'name' => $l->product_name,
                'received_quantity' => PrintFormat::quantity((string) $l->received_quantity, $l->uom_code),
                'unit_cost' => $m($l->unit_cost), 'line_cost' => $m($l->line_cost),
                'manufactured_date' => $l->manufactured_date, 'best_before_date' => $l->best_before_date,
                // upstream facts as stated by the supplier (null = unknown); distinct from received_at / business_date
                'collection_date' => $l->collection_date ?? null, 'supplier_reference' => $l->supplier_reference ?? null,
                'dispositions' => array_map(fn ($x) => ['type' => $x->disposition_type, 'quantity' => PrintFormat::quantity((string) $x->quantity, $l->uom_code),
                    'target_sku' => $x->target_sku, 'reason' => $x->reason], $disp[$l->id] ?? []),
                'lot' => isset($lots[$l->id]) ? ['id' => $lots[$l->id]->id, 'number' => $lots[$l->id]->number,
                    'qr_payload' => IdentifierCodec::qr('lot', $lots[$l->id]->id), 'barcode' => IdentifierCodec::barcode($lots[$l->id]->number)] : null,
            ], $d['lines']),
            'total_cost' => $m($g->total_cost),
            'notes' => $g->notes,
        ]);
    }

    /**
     * LOT_LABEL. Wording rules (addendum §6): the DG Way received date is
     * labelled "Diterima DG Way" and NEVER as production/batch/collection
     * date; "Tanggal Pengumpulan" appears only when a collection date was
     * genuinely recorded; LEGACY lots show that provenance is not recorded.
     * `display_lines` is the ordered, ready-to-print text; the structured
     * fields carry the same facts for apps that lay the label out themselves.
     */
    public function lotLabel(string $lotIdOrNumber): array
    {
        $l = $this->queries->lot($lotIdOrNumber);
        $legacy = $l->origin === 'LEGACY';
        $qty = $l->original_quantity === null ? null : PrintFormat::quantity((string) $l->original_quantity, $l->uom_code);
        $collection = $l->collection_date ?? null;
        $reference = $l->supplier_reference ?? null;

        $lines = [['key' => 'product', 'label' => 'Produk', 'value' => "{$l->sku} — {$l->product_name}"]];
        $lines[] = ['key' => 'received_date', 'label' => 'Diterima DG Way', 'value' => (string) $l->business_date];
        if ($collection !== null) {
            $lines[] = ['key' => 'collection_date', 'label' => 'Tanggal Pengumpulan', 'value' => (string) $collection];
        }
        if ($l->supplier_id !== null) {
            $lines[] = ['key' => 'supplier', 'label' => 'Pemasok', 'value' => $l->supplier_name];
        }
        if ($reference !== null) {
            $lines[] = ['key' => 'supplier_reference', 'label' => 'Ref. Pemasok', 'value' => $reference];
        }
        if ($l->goods_receipt_id !== null) {
            $lines[] = ['key' => 'goods_receipt', 'label' => 'Penerimaan', 'value' => $l->goods_receipt_number];
        }
        if ($l->parent_lot_id !== null) {
            $lines[] = ['key' => 'derived_from', 'label' => 'Turunan dari', 'value' => $l->parent_number];
        }
        if ($qty !== null) {
            $lines[] = ['key' => 'original_quantity', 'label' => 'Jumlah Awal', 'value' => $qty['formatted'].' '.$qty['uom']];
        }
        if ($l->best_before_date !== null) {
            $lines[] = ['key' => 'best_before_date', 'label' => 'Baik Digunakan Sebelum', 'value' => (string) $l->best_before_date];
        }
        if ($legacy) {
            $lines[] = ['key' => 'provenance', 'label' => 'Asal', 'value' => 'Tidak tercatat (stok sebelum pencatatan lot)'];
        }

        return $this->envelope('LOT_LABEL', [
            'lot_id' => $l->id,
            'lot_number' => $l->number,
            'origin' => $l->origin,
            'product' => ['sku' => $l->sku, 'name' => $l->product_name],
            'node' => ['code' => $l->node_code],
            // DG Way's own receipt of the goods — NOT a production or collection date
            'received_by_dgway' => ['business_date' => (string) $l->business_date, 'received_at' => self::atom($l->received_at)],
            // upstream facts, only when recorded; null = unknown
            'collection_date' => $collection === null ? null : (string) $collection,
            'supplier_reference' => $reference,
            'original_quantity' => $qty,
            'uom' => $l->uom_code,
            'supplier' => $l->supplier_id === null ? null : ['code' => $l->supplier_code, 'name' => $l->supplier_name],
            'goods_receipt' => $l->goods_receipt_id === null ? null : ['number' => $l->goods_receipt_number],
            'derived_from' => $l->parent_lot_id === null ? null : ['lot_number' => $l->parent_number],
            'best_before_date' => $l->best_before_date,
            'provenance' => $legacy ? 'UNATTRIBUTED_LEGACY' : 'RECORDED',
            'display_lines' => $lines,
            'codes' => [
                'qr' => ['payload' => IdentifierCodec::qr('lot', $l->id), 'contract_version' => IdentifierCodec::CONTRACT_VERSION],
                'barcode' => IdentifierCodec::barcode($l->number),
            ],
        ], null, 'LABEL');
    }

    private function envelope(string $type, array $document, ?int $widthMm = null, string $medium = 'RECEIPT'): array
    {
        $settings = $this->settings->current();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'document_type' => $type,
            'generated_at' => $this->clock->now()->format(DATE_ATOM),
            'business' => $settings->business(),
            'layout' => $medium === 'LABEL'
                ? ['medium' => 'LABEL']
                : ['medium' => 'RECEIPT', 'paper_width_mm' => $widthMm ?? $settings->receiptWidthMm, 'supported_widths_mm' => PrintSettings::WIDTHS],
            'document' => $document,
        ];
    }

    private static function atom(mixed $ts): ?string
    {
        return $ts === null ? null : (new \DateTimeImmutable((string) $ts))->format(DATE_ATOM);
    }
}
