# A5-B performance sanity — 2026-10-03T08:23:54+00:00

Busiest business date (dashboard/activity): 2026-09-04

## Volume

- products                 1000
- business_partners        1001
- goods_receipts            400
- inventory_lots           1200
- sales                    3000
- sale_lines               5951
- payments                 2336
- customer_returns           45
- stock_movements          9511
- stock_lot_balances       2193
- audit_logs              17960

## Read models (warm, median of 5 runs; queries = SQL statements per call)

| Read model | median ms | queries |
| --- | ---: | ---: |
| GET /lots (page 1) | 5.1 | 2 |
| GET /lots (page 20) | 9.1 | 2 |
| GET /lots?q=PRD-0001 | 13.5 | 2 |
| GET /lots?q=peternak uji 7 | 15.3 | 2 |
| GET /lots?status=active&product_id | 4.2 | 2 |
| GET /lots/{id} | 4.8 | 4 |
| GET /lots/{id}/movements | 1.5 | 3 |
| GET /lots/{id}/destinations | 2.7 | 3 |
| GET /lots/{id}/lineage | 1.8 | 3 |
| GET /inventory/lot-balances (product) | 4.0 | 2 |
| GET /inventory/balances | 2.4 | 2 |
| GET /inventory/movements (product) | 2.2 | 2 |
| GET /dashboard (busiest day) | 19.4 | 18 |
| GET /activity (busiest day) | 23.2 | 2 |
| GET /search?q=PRD-00 | 12.2 | 2 |
| GET /search?q=pelanggan uji 9 (partners) | 3.3 | 2 |
| GET /search?q=08120000077 (phone) | 2.9 | 2 |
| GET /search?q=SAL-26 (documents) | 10.7 | 2 |
| GET /receivables/summary | 1.6 | 1 |
| GET /receivables/customers | 6.6 | 2 |

## Plans of the hot statements (EXPLAIN ANALYZE)

### FIFO lot candidates of one position (allocation, FOR UPDATE)
```
Sort (actual rows=9 loops=1)
  Sort Key: l.business_date, l.received_at, l.number
  Sort Method: quicksort  Memory: 25kB
  InitPlan 1 (returns $0)
    ->  Limit (actual rows=1 loops=1)
          ->  Seq Scan on nodes (actual rows=1 loops=1)
  ->  Hash Join (actual rows=9 loops=1)
        Hash Cond: (l.id = b.lot_id)
        ->  Seq Scan on inventory_lots l (actual rows=1200 loops=1)
        ->  Hash (actual rows=9 loops=1)
              Buckets: 1024  Batches: 1  Memory Usage: 9kB
              ->  Bitmap Heap Scan on stock_lot_balances b (actual rows=9 loops=1)
                    Recheck Cond: ((product_id = '01a100da-5549-7292-abb1-34f926fc61fc'::uuid) AND (node_id = $0) AND ((bucket)::text = 'AVAILABLE'::text))
                    Filter: (quantity > '0'::numeric)
                    Rows Removed by Filter: 8
                    Heap Blocks: exact=13
                    ->  Bitmap Index Scan on stock_lot_balances_product_id_node_id_bucket_index (actual rows=17 loops=1)
                          Index Cond: ((product_id = '01a100da-5549-7292-abb1-34f926fc61fc'::uuid) AND (node_id = $0) AND ((bucket)::text = 'AVAILABLE'::text))
Planning Time: 0.266 ms
Execution Time: 0.324 ms
```

### Movements of one lot
```
Limit (actual rows=9 loops=1)
  ->  Sort (actual rows=9 loops=1)
        Sort Key: sequence DESC
        Sort Method: quicksort  Memory: 27kB
        ->  Bitmap Heap Scan on stock_movements (actual rows=9 loops=1)
              Recheck Cond: (lot_id = '01a100da-6fb7-7248-ba1e-e04438b9c692'::uuid)
              Heap Blocks: exact=6
              ->  Bitmap Index Scan on stock_movements_lot_id_sequence_index (actual rows=9 loops=1)
                    Index Cond: (lot_id = '01a100da-6fb7-7248-ba1e-e04438b9c692'::uuid)
Planning Time: 0.088 ms
Execution Time: 0.041 ms
```

### Destinations of one lot (SALE movements)
```
GroupAggregate (actual rows=6 loops=1)
  Group Key: source_line_id
  ->  Sort (actual rows=6 loops=1)
        Sort Key: source_line_id
        Sort Method: quicksort  Memory: 25kB
        ->  Bitmap Heap Scan on stock_movements (actual rows=6 loops=1)
              Recheck Cond: (lot_id = '01a100da-6fb7-7248-ba1e-e04438b9c692'::uuid)
              Filter: ((movement_type)::text = 'SALE'::text)
              Rows Removed by Filter: 3
              Heap Blocks: exact=6
              ->  Bitmap Index Scan on stock_movements_lot_id_sequence_index (actual rows=9 loops=1)
                    Index Cond: (lot_id = '01a100da-6fb7-7248-ba1e-e04438b9c692'::uuid)
Planning Time: 0.074 ms
Execution Time: 0.046 ms
```

### Sales of a day (dashboard)
```
Aggregate (actual rows=1 loops=1)
  ->  Bitmap Heap Scan on sales (actual rows=104 loops=1)
        Recheck Cond: (business_date = '2026-09-04'::date)
        Filter: ((status)::text = ANY ('{CONFIRMED,COMPLETED}'::text[]))
        Heap Blocks: exact=4
        ->  Bitmap Index Scan on sales_business_date_status_index (actual rows=126 loops=1)
              Index Cond: (business_date = '2026-09-04'::date)
Planning Time: 0.070 ms
Execution Time: 0.102 ms
```

### Partner search by name
```
Limit (actual rows=25 loops=1)
  ->  Seq Scan on business_partners (actual rows=25 loops=1)
        Filter: (lower((name)::text) ~~ '%pelanggan uji 9%'::text)
        Rows Removed by Filter: 900
Planning Time: 0.084 ms
Execution Time: 0.446 ms
```
