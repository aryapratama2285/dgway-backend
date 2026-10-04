<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Catalog\CreateCategory;
use App\Application\Catalog\CreateProduct;
use App\Application\Inventory\InventoryQueries;
use App\Application\Inventory\LotQueries;
use App\Application\Inventory\RecordInventoryOperation;
use App\Application\Operations\OperationsQueries;
use App\Application\Operations\OwnerDashboard;
use App\Application\Partner\CreateBusinessPartner;
use App\Application\Procurement\CreateGoodsReceipt;
use App\Application\Procurement\PostGoodsReceipt;
use App\Application\Sales\CreateSale;
use App\Application\Sales\RecordCustomerReturn;
use App\Application\Sales\SalesQueries;
use App\Application\Shared\Query\PageRequest;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * A5-B §24 — performance sanity on a realistic small-business volume.
 * DEVELOPMENT ONLY (refuses APP_ENV=production): seeds through the real use
 * cases (so every invariant, trigger and lot rule runs), then times the
 * mobile read models and records their query counts and plans.
 *
 *   php artisan dgway:dev:perf --seed --products=1000 --partners=1000 --receipts=400 --sales=3000
 *   php artisan dgway:dev:perf            (report only, on already seeded data)
 */
final class PerformanceSanityCommand extends Command
{
    protected $signature = 'dgway:dev:perf {--seed} {--products=1000} {--partners=1000} {--receipts=400} {--sales=3000} {--output=}';

    protected $description = '[dev only] Seed a realistic volume through the use cases and time the read models';

    public function handle(ConnectionInterface $db, Clock $clock): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: development-only command.');

            return self::FAILURE;
        }
        if ($this->option('seed')) {
            $this->seed($db, $clock);
        }
        $report = $this->report($db, $clock);
        $text = implode("\n", $report)."\n";
        $this->line($text);
        if ($path = $this->option('output')) {
            file_put_contents($path, $text);
        }

        return self::SUCCESS;
    }

    private function seed(ConnectionInterface $db, Clock $clock): void
    {
        $actor = Actor::system('perf-seed');
        $nodeId = (string) $db->table('nodes')->where('code', config('dgway.default_node_code'))->value('id')
            ?: throw new \RuntimeException('Run php artisan dgway:bootstrap first.');
        $t0 = microtime(true);
        mt_srand(20261004); // reproducible volume

        $cat = app(CreateCategory::class)->execute($actor, 'PERF-'.mt_rand(1000, 9999), 'Perf category');
        $products = [];
        for ($i = 1; $i <= (int) $this->option('products'); $i++) {
            $products[] = app(CreateProduct::class)->execute($actor, sprintf('PRD-%05d', $i), "Produk Uji {$i} ".['Telur', 'Ayam', 'Beras', 'Minyak'][$i % 4], $cat->id()->value, 'KG')->id()->value;
        }
        $suppliers = $customers = [];
        $partners = (int) $this->option('partners');
        for ($i = 1; $i <= $partners; $i++) {
            $supplier = $i <= (int) ceil($partners / 10);
            $p = app(CreateBusinessPartner::class)->execute($actor, [
                'code' => sprintf($supplier ? 'SUP-%05d' : 'CUS-%05d', $i), 'name' => ($supplier ? 'Peternak ' : 'Pelanggan ')."Uji {$i}",
                'party_type' => 'ORGANIZATION', 'phone' => sprintf('+62812%08d', $i),
                $supplier ? 'supplier' : 'customer' => $supplier ? ['term_days' => 0] : ['customer_type' => 'CATERING', 'term_days' => 14, 'credit_limit' => null],
            ]);
            $supplier ? $suppliers[] = $p->id()->value : $customers[] = $p->id()->value;
        }
        $this->info(sprintf('master data: %d products, %d partners in %.1fs', count($products), $partners, microtime(true) - $t0));

        $today = $clock->today();
        $hot = array_slice($products, 0, 60); // most movement concentrates on few SKUs (realistic, stresses lots)
        $receipts = (int) $this->option('receipts');
        for ($i = 0; $i < $receipts; $i++) {
            $date = $today->addDays(-30 + intdiv($i * 30, max(1, $receipts)))->toString();
            $lines = [];
            foreach (array_rand(array_flip($hot), 3) as $pid) {
                $qty = (string) mt_rand(100, 400);
                $loss = (string) mt_rand(0, 5);
                $lines[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_cost' => (string) mt_rand(20000, 24000),
                    'dispositions' => $loss === '0' ? [['type' => 'ACCEPT', 'quantity' => $qty]] : [['type' => 'ACCEPT', 'quantity' => (string) ((int) $qty - (int) $loss)], ['type' => 'LOSS', 'quantity' => $loss]]];
            }
            $gr = app(CreateGoodsReceipt::class)->execute($actor, $suppliers[array_rand($suppliers)], $nodeId, $lines, null, $date);
            app(PostGoodsReceipt::class)->execute($actor, $gr->id()->value);
        }
        $this->info(sprintf('receipts: %d posted in %.1fs', $receipts, microtime(true) - $t0));

        $sales = (int) $this->option('sales');
        $saleIds = [];
        $failed = 0;
        for ($i = 0; $i < $sales; $i++) {
            $date = $today->addDays(-29 + intdiv($i * 29, max(1, $sales)))->toString();
            $customer = mt_rand(1, 3) === 1 ? null : $customers[array_rand($customers)];
            $lines = [];
            foreach ((array) array_rand(array_flip($hot), mt_rand(1, 3)) as $pid) {
                $lines[] = ['product_id' => $pid, 'quantity' => (string) mt_rand(1, 25), 'unit_price' => (string) mt_rand(25000, 28000)];
            }
            $total = array_sum(array_map(fn ($l) => (int) $l['quantity'] * (int) $l['unit_price'], $lines));
            $pay = $customer === null ? $total : [0, (int) ($total / 2), $total][mt_rand(0, 2)];
            try {
                $sale = app(CreateSale::class)->execute($actor, $customer, $nodeId, $lines, $date, null, true,
                    $pay > 0 ? [['method' => ['CASH', 'BANK_TRANSFER', 'QRIS'][mt_rand(0, 2)], 'amount' => (string) $pay]] : []);
                $saleIds[] = [$sale->id()->value, $sale->lines()[0]->id->value];
            } catch (DomainException) {
                $failed++; // e.g. INSUFFICIENT_STOCK late in the period: a real outcome, not an error
            }
        }
        foreach (array_slice($saleIds, 0, 100) as [$saleId, $lineId]) {
            try {
                app(RecordCustomerReturn::class)->execute($actor, $saleId, [['sale_line_id' => $lineId, 'quantity' => '1', 'disposition' => 'LOSS']], 'perf: pecah');
            } catch (DomainException) {
            }
        }
        foreach (array_slice($hot, 0, 30) as $pid) {
            try {
                app(RecordInventoryOperation::class)->recordLoss($actor, $nodeId, $pid, '2', 'perf: susut');
            } catch (DomainException) {
            }
        }
        $this->info(sprintf('sales: %d confirmed (%d refused by business rules), returns, losses in %.1fs', count($saleIds), $failed, microtime(true) - $t0));
    }

    /** @return list<string> */
    private function report(ConnectionInterface $db, Clock $clock): array
    {
        $lots = app(LotQueries::class);
        $ops = app(OperationsQueries::class);
        $inv = app(InventoryQueries::class);
        $sales = app(SalesQueries::class);
        // the busiest business date, so dashboard/activity are timed on a full day
        $today = (string) ($db->table('sales')->groupBy('business_date')->orderByRaw('count(*) desc')->value('business_date') ?? $clock->today()->toString());
        $p1 = new PageRequest(1, 25);
        $deep = new PageRequest(20, 25);
        $lotId = (string) $db->table('inventory_lots')->where('origin', 'RECEIPT')->orderBy('number')->value('id');
        $productId = (string) $db->table('stock_movements')->where('movement_type', 'SALE')->groupBy('product_id')->orderByRaw('count(*) desc')->value('product_id');

        $out = ['# A5-B performance sanity — '.date('c'), '', "Busiest business date (dashboard/activity): {$today}", '', '## Volume', ''];
        foreach (['products', 'business_partners', 'goods_receipts', 'inventory_lots', 'sales', 'sale_lines', 'payments', 'customer_returns', 'stock_movements', 'stock_lot_balances', 'audit_logs'] as $t) {
            $out[] = sprintf('- %-20s %8d', $t, $db->table($t)->count());
        }
        $out[] = '';
        $out[] = '## Read models (warm, median of 5 runs; queries = SQL statements per call)';
        $out[] = '';
        $out[] = '| Read model | median ms | queries |';
        $out[] = '| --- | ---: | ---: |';
        $cases = [
            'GET /lots (page 1)' => fn () => $lots->lots([], $p1),
            'GET /lots (page 20)' => fn () => $lots->lots([], $deep),
            'GET /lots?q=PRD-0001' => fn () => $lots->lots(['q' => 'PRD-0001'], $p1),
            'GET /lots?q=peternak uji 7' => fn () => $lots->lots(['q' => 'peternak uji 7'], $p1),
            'GET /lots?status=active&product_id' => fn () => $lots->lots(['status' => 'active', 'product_id' => $productId], $p1),
            'GET /lots/{id}' => fn () => $lots->lot($lotId),
            'GET /lots/{id}/movements' => fn () => $lots->movements($lotId, $p1),
            'GET /lots/{id}/destinations' => fn () => $lots->destinations($lotId, $p1),
            'GET /lots/{id}/lineage' => fn () => $lots->lineage($lotId),
            'GET /inventory/lot-balances (product)' => fn () => $lots->balances(null, $productId, null, false, $p1),
            'GET /inventory/balances' => fn () => $inv->balances(null, null, null, false, $p1),
            'GET /inventory/movements (product)' => fn () => $inv->movements(['product_id' => $productId], $p1),
            'GET /dashboard (busiest day)' => fn () => app(OwnerDashboard::class)->forDate($today, 'Asia/Jakarta', 'IDR'),
            'GET /activity (busiest day)' => fn () => $ops->activity($today, null, $p1),
            'GET /search?q=PRD-00' => fn () => $ops->search('PRD-00', 'all', $p1),
            'GET /search?q=pelanggan uji 9 (partners)' => fn () => $ops->search('pelanggan uji 9', 'partners', $p1),
            'GET /search?q=08120000077 (phone)' => fn () => $ops->search('08120000077', 'partners', $p1),
            'GET /search?q=SAL-26 (documents)' => fn () => $ops->search('SAL-26', 'documents', $p1),
            'GET /receivables/summary' => fn () => $sales->receivableSummary($today),
            'GET /receivables/customers' => fn () => $sales->receivablesByCustomer([], $today, $p1),
        ];
        foreach ($cases as $label => $fn) {
            $fn(); // warm
            $times = [];
            $queries = 0;
            for ($i = 0; $i < 5; $i++) {
                $db->flushQueryLog();
                $db->enableQueryLog();
                $t = microtime(true);
                $fn();
                $times[] = (microtime(true) - $t) * 1000;
                $queries = count($db->getQueryLog());
                $db->disableQueryLog();
            }
            sort($times);
            $out[] = sprintf('| %s | %.1f | %d |', $label, $times[2], $queries);
        }

        $out[] = '';
        $out[] = '## Plans of the hot statements (EXPLAIN ANALYZE)';
        $plans = [
            'FIFO lot candidates of one position (allocation, FOR UPDATE)' => ["SELECT b.lot_id FROM stock_lot_balances b JOIN inventory_lots l ON l.id = b.lot_id
                WHERE b.product_id = ? AND b.node_id = (SELECT id FROM nodes LIMIT 1) AND b.bucket = 'AVAILABLE' AND b.quantity > 0
                ORDER BY l.business_date, l.received_at, l.number", [$productId]],
            'Movements of one lot' => ['SELECT * FROM stock_movements WHERE lot_id = ? ORDER BY sequence DESC LIMIT 25', [$lotId]],
            'Destinations of one lot (SALE movements)' => ["SELECT source_line_id, sum(quantity) FROM stock_movements WHERE lot_id = ? AND movement_type = 'SALE' GROUP BY 1", [$lotId]],
            'Sales of a day (dashboard)' => ["SELECT count(*), sum(grand_total) FROM sales WHERE business_date = ? AND status IN ('CONFIRMED','COMPLETED')", [$today]],
            'Partner search by name' => ["SELECT id FROM business_partners WHERE lower(name) LIKE '%pelanggan uji 9%' LIMIT 25", []],
        ];
        foreach ($plans as $label => [$sql, $bind]) {
            $out[] = '';
            $out[] = "### {$label}";
            $out[] = '```';
            foreach ($db->select('EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY ON) '.$sql, $bind) as $row) {
                $out[] = (string) $row->{'QUERY PLAN'};
            }
            $out[] = '```';
        }

        return $out;
    }
}
