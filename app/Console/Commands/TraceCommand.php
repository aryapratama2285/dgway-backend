<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Inventory\LotQueries;
use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Programmer traceability (A5 addendum §10). Read-only.
 *
 *   php artisan dgway:trace SAL-261003-002
 *   php artisan dgway:trace 01a1…   (any document / payment / movement / correlation UUID)
 *   php artisan dgway:trace SAL-261003-002 --json
 *   php artisan dgway:trace LOT-261003-001   (A5-B: lot number or lot UUID)
 *
 * Follows: business document → its lines → related documents (payments,
 * reversals, returns, PO ↔ GR) → inventory movements (source + correlation)
 * → actors → audit entries, so an incident or a reconciliation gap can be
 * followed from any identifier to all of its consequences.
 *
 * A lot reference follows LOT → PRODUCT → SUPPLIER → GOODS RECEIPT →
 * DISPOSITIONS → LINEAGE → MOVEMENTS → SALES → CUSTOMERS → AUDIT.
 * Document traces list the lots their movements touched.
 */
final class TraceCommand extends Command
{
    protected $signature = 'dgway:trace {reference : document number or UUID} {--json : machine-readable output}';

    protected $description = 'Trace a business transaction through documents, ledger, money, actors and audit';

    /** table => document type (tables that carry a business document number) */
    private const DOCUMENTS = [
        'sales' => 'sale',
        'payments' => 'payment',
        'customer_returns' => 'customer_return',
        'goods_receipts' => 'goods_receipt',
        'purchase_orders' => 'purchase_order',
        'inventory_operations' => 'inventory_operation',
    ];

    public function handle(ConnectionInterface $db, LotQueries $lots): int
    {
        $ref = trim((string) $this->argument('reference'));
        $lotId = $this->locateLot($db, $ref);
        if ($lotId !== null) {
            return $this->traceLot($db, $lots, $ref, $lotId);
        }
        $root = $this->locate($db, $ref);
        if ($root === null) {
            $this->error("Nothing found for '{$ref}'.");

            return self::FAILURE;
        }

        $docs = $this->related($db, $root['type'], $root['id']);
        $ids = array_values(array_unique(array_column($docs, 'id')));
        $movements = $db->table('stock_movements')
            ->where(fn ($q) => $q->whereIn('source_document_id', $ids)->orWhereIn('correlation_id', $ids))
            ->orderBy('sequence')
            ->get(['id', 'sequence', 'movement_type', 'product_id', 'node_id', 'bucket', 'signed_quantity', 'business_date', 'source_document_type',
                'source_document_id', 'correlation_id', 'reverses_movement_id', 'actor_user_id', 'lot_id'])->map(fn ($r) => (array) $r)->all();
        $lotIds = array_values(array_filter(array_unique(array_column($movements, 'lot_id'))));
        $touchedLots = $db->table('inventory_lots')->whereIn('id', $lotIds)->orderBy('number')->get(['id', 'number', 'origin', 'product_id', 'parent_lot_id'])->map(fn ($r) => (array) $r)->all();
        $audit = $db->table('audit_logs')->whereIn('entity_id', $ids)->orderBy('occurred_at')->orderBy('id')
            ->get(['id', 'occurred_at', 'business_date', 'action', 'entity_type', 'entity_id', 'actor_user_id', 'actor_label', 'context'])
            ->map(fn ($r) => ['context' => json_decode((string) $r->context, true)] + (array) $r)->all();
        $actorIds = array_values(array_filter(array_unique(array_merge(
            array_column($docs, 'actor_user_id'), array_column($movements, 'actor_user_id'), array_column($audit, 'actor_user_id'),
        ))));
        $actors = $db->table('users')->whereIn('id', $actorIds)->get(['id', 'name', 'email'])->map(fn ($r) => (array) $r)->all();

        $trace = [
            'reference' => $ref,
            'root' => $root,
            'documents' => $docs,
            'stock_movements' => $movements,
            'correlation_ids' => array_values(array_unique(array_column($movements, 'correlation_id'))),
            'lots' => $touchedLots,
            'actors' => $actors,
            'audit' => $audit,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Trace of {$ref} → {$root['type']} {$root['id']}");
        $this->table(['type', 'number', 'id', 'status', 'business_date', 'amount'], array_map(fn ($d) => [$d['type'], $d['number'] ?? '-', $d['id'], $d['status'] ?? '-', $d['business_date'] ?? '-', $d['amount'] ?? '-'], $docs));
        $this->line('Stock movements:');
        $this->table(['#', 'type', 'bucket', 'qty', 'source', 'correlation', 'reverses'], array_map(fn ($m) => [$m['sequence'], $m['movement_type'], $m['bucket'], $m['signed_quantity'],
            $m['source_document_type'].':'.substr((string) $m['source_document_id'], 0, 8), substr((string) $m['correlation_id'], 0, 8), $m['reverses_movement_id'] ? substr((string) $m['reverses_movement_id'], 0, 8) : '-'], $movements));
        $this->line('Audit:');
        $this->table(['occurred_at', 'action', 'entity', 'actor'], array_map(fn ($a) => [$a['occurred_at'], $a['action'], $a['entity_type'].':'.substr((string) $a['entity_id'], 0, 8), $a['actor_label']], $audit));

        return self::SUCCESS;
    }

    private function locateLot(ConnectionInterface $db, string $ref): ?string
    {
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref) === 1;
        $id = $db->table('inventory_lots')->where($isUuid ? 'id' : 'number', $isUuid ? $ref : strtoupper($ref))->value('id');

        return $id === null ? null : (string) $id;
    }

    private function traceLot(ConnectionInterface $db, LotQueries $lots, string $ref, string $lotId): int
    {
        $all = function (callable $page): array {
            $items = [];
            for ($n = 1; ; $n++) {
                /** @var Page $p */
                $p = $page(new PageRequest($n, PageRequest::MAX_PER_PAGE));
                array_push($items, ...$p->items);
                if ($n >= $p->lastPage()) {
                    return $items;
                }
            }
        };
        $lot = $lots->lot($lotId);
        $movements = array_reverse($all(fn (PageRequest $r) => $lots->movements($lotId, $r))); // chronological
        $destinations = $all(fn (PageRequest $r) => $lots->destinations($lotId, $r));
        $lineage = $lots->lineage($lotId);

        $entityIds = array_values(array_filter(array_unique(array_merge(
            [$lotId, $lot['goods_receipt']['id'] ?? null],
            array_column($destinations, 'sale_id'),
            array_column(array_column($movements, 'source'), 'id'),
        ))));
        $audit = $db->table('audit_logs')->whereIn('entity_id', $entityIds)->orderBy('occurred_at')->orderBy('id')
            ->get(['id', 'occurred_at', 'business_date', 'action', 'entity_type', 'entity_id', 'actor_user_id', 'actor_label', 'context'])
            ->map(fn ($r) => ['context' => json_decode((string) $r->context, true)] + (array) $r)->all();

        $trace = [
            'reference' => $ref,
            'root' => ['type' => 'inventory_lot', 'id' => $lotId],
            'lot' => $lot,
            'lineage' => ['ancestors' => $lineage['ancestors'], 'descendants' => $lineage['descendants']],
            'stock_movements' => $movements,
            'destinations' => $destinations,
            'customers' => array_values(array_unique(array_filter(array_map(fn ($d) => $d['customer']['code'] ?? null, $destinations)))),
            'audit' => $audit,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Trace of lot {$lot['number']} ({$lot['origin']}, {$lot['status']}) → {$lot['product']['sku']} @ {$lot['node']['code']}");
        $this->line('Supplier: '.($lot['supplier'] ? "{$lot['supplier']['code']} {$lot['supplier']['name']}" : 'none ('.$lot['provenance'].')')
            .' · Receipt: '.($lot['goods_receipt']['number'] ?? '-').' · Original: '.($lot['original_quantity'] ?? 'unknown (legacy)')
            .' · Remaining: '.$lot['remaining']['on_hand'].' (available '.$lot['remaining']['available'].')');
        // two different business facts, never merged (A5-C)
        $this->line('Received by DG Way: '.$lot['business_date'].' ('.$lot['received_at'].')'
            .' · Collection date: '.($lot['collection_date'] ?? 'unknown')
            .' · Supplier reference: '.($lot['supplier_reference'] ?? 'none'));
        $this->table(['disposition', 'qty', 'target'], array_map(fn ($d) => [$d['type'], $d['quantity'], $d['target_sku'] ?? '-'], $lot['dispositions']));
        $this->line('Lineage: '.implode(' → ', array_merge(array_column($lineage['ancestors'], 'number'), [$lot['number']], array_column($lineage['descendants'], 'number'))));
        $this->table(['#', 'type', 'bucket', 'qty', 'source'], array_map(fn ($m) => [$m['sequence'], $m['movement_type'], $m['bucket'], $m['signed_quantity'], $m['source']['type'].':'.substr((string) $m['source']['id'], 0, 8)], $movements));
        $this->table(['sale', 'date', 'customer', 'sold', 'returned', 'net'], array_map(fn ($d) => [$d['sale_number'], $d['business_date'], $d['customer']['name'] ?? 'walk-in', $d['sold'], $d['returned'], $d['net']], $destinations));
        $this->table(['occurred_at', 'action', 'entity', 'actor'], array_map(fn ($a) => [$a['occurred_at'], $a['action'], $a['entity_type'].':'.substr((string) $a['entity_id'], 0, 8), $a['actor_label']], $audit));

        return self::SUCCESS;
    }

    /** @return array{type: string, id: string}|null */
    private function locate(ConnectionInterface $db, string $ref): ?array
    {
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref) === 1;
        foreach (self::DOCUMENTS as $table => $type) {
            $id = $db->table($table)->where($isUuid ? 'id' : 'number', $isUuid ? $ref : strtoupper($ref))->value('id');
            if ($id !== null) {
                return ['type' => $type, 'id' => $id];
            }
        }
        if (! $isUuid) {
            return null;
        }
        if ($r = $db->table('payment_reversals')->where('id', $ref)->first(['payment_id'])) {
            return ['type' => 'payment', 'id' => $r->payment_id];
        }
        // a movement id or a correlation id → its source document
        $m = $db->table('stock_movements')->where('id', $ref)->orWhere('correlation_id', $ref)->orderBy('sequence')->first(['source_document_type', 'source_document_id']);
        if ($m !== null) {
            return ['type' => $m->source_document_type, 'id' => $m->source_document_id];
        }

        return null;
    }

    /** @return list<array<string, mixed>> the root document and everything economically tied to it */
    private function related(ConnectionInterface $db, string $type, string $id): array
    {
        $docs = [];
        $add = function (string $t, object $r) use (&$docs): void {
            $docs[] = ['type' => $t] + (array) $r;
        };
        $saleIds = [];
        switch ($type) {
            case 'sale':
                $saleIds = [$id];
                break;
            case 'payment':
            case 'customer_return':
                $saleIds = [(string) $db->table($type === 'payment' ? 'payments' : 'customer_returns')->where('id', $id)->value('sale_id')];
                break;
            case 'goods_receipt':
            case 'purchase_order':
                $poId = $type === 'purchase_order' ? $id : $db->table('goods_receipts')->where('id', $id)->value('purchase_order_id');
                foreach ($poId ? $db->table('purchase_orders')->where('id', $poId)->get(['id', 'number', 'status', 'business_date', 'created_by as actor_user_id']) : [] as $r) {
                    $add('purchase_order', $r);
                }
                $grs = $db->table('goods_receipts')->where($poId ? 'purchase_order_id' : 'id', $poId ?: $id)
                    ->get(['id', 'number', 'status', 'business_date', 'posted_by as actor_user_id']);
                foreach ($grs as $r) {
                    $r->amount = (string) $db->table('goods_receipt_lines')->where('goods_receipt_id', $r->id)->sum('line_cost');
                    $add('goods_receipt', $r);
                }

                return $docs;
            case 'inventory_operation':
                foreach ($db->table('inventory_operations')->where('id', $id)->get(['id', 'number', 'operation_type as status', 'business_date', 'actor_user_id']) as $r) {
                    $add('inventory_operation', $r);
                }

                return $docs;
        }

        foreach ($db->table('sales')->whereIn('id', $saleIds)->get(['id', 'number', 'status', 'business_date', 'grand_total as amount', 'confirmed_by as actor_user_id']) as $r) {
            $add('sale', $r);
        }
        $payments = $db->table('payments as p')->leftJoin('payment_reversals as r', 'r.payment_id', '=', 'p.id')->whereIn('p.sale_id', $saleIds)
            ->orderBy('p.created_at')->get(['p.id', 'p.number', 'p.business_date', 'p.amount', 'p.actor_user_id', 'r.id as reversal_id']);
        foreach ($payments as $r) {
            $r->status = $r->reversal_id ? 'REVERSED' : 'POSTED';
            $add('payment', $r);
            if ($r->reversal_id) {
                $add('payment_reversal', $db->table('payment_reversals')->where('id', $r->reversal_id)->first(['id', 'business_date', 'reason as status', 'actor_user_id']));
            }
        }
        foreach ($db->table('customer_returns')->whereIn('sale_id', $saleIds)->orderBy('created_at')->get(['id', 'number', 'business_date', 'total_value as amount', 'actor_user_id']) as $r) {
            $add('customer_return', $r);
        }

        return $docs;
    }
}
