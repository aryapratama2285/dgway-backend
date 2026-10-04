<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Migration reconciliation (A5 addendum §7).
 *
 * Produces a deterministic JSON "fingerprint" of the Generation-1 data:
 *
 *   invariants  economic meaning: counts, Σ valid payments, Σ outstanding,
 *               outstanding per customer, ledger Σ per product/node/bucket …
 *   checksums   per preserved table: row count + md5 over the semantic
 *               columns declared in config/data_contract.php, ordered by id
 *               (strong check: any changed value, row or id is detected).
 *
 * Usage around any migration / schema evolution:
 *   php artisan dgway:data:fingerprint --output=before.json
 *   … migrate …
 *   php artisan dgway:data:fingerprint --compare=before.json   (exit 1 on any difference)
 *
 * A future system (Project B, any technology) reproduces the same SQL
 * projections to prove it carried the history over unchanged.
 */
final class DataFingerprintCommand extends Command
{
    protected $signature = 'dgway:data:fingerprint
        {--output= : Write the fingerprint JSON to this file}
        {--compare= : Compare with a previously saved fingerprint; exit 1 on difference}
        {--only=all : all | invariants | checksums}
        {--allow-additions : When comparing, accept keys that did not exist in the older fingerprint (schema added later)}';

    protected $description = 'Deterministic fingerprint of Generation-1 data for migration reconciliation';

    public function handle(ConnectionInterface $db): int
    {
        $previousZone = (string) $db->selectOne('SHOW TIME ZONE')->TimeZone;
        $db->statement("SET TIME ZONE 'UTC'"); // timestamps render identically everywhere
        try {
            return $this->fingerprint($db);
        } finally {
            $db->statement('SET TIME ZONE '.$db->getPdo()->quote($previousZone));
        }
    }

    private function fingerprint(ConnectionInterface $db): int
    {
        $only = (string) $this->option('only');
        $fp = ['format' => 'dgway-data-fingerprint/1'];
        if ($only !== 'checksums') {
            $fp['invariants'] = $this->invariants($db);
        }
        if ($only !== 'invariants') {
            $fp['checksums'] = $this->checksums($db);
        }
        $json = json_encode($fp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if ($path = $this->option('output')) {
            file_put_contents($path, $json);
            $this->info("Fingerprint written to {$path}");
        }

        if ($path = $this->option('compare')) {
            if (! is_file($path)) {
                $this->error("No fingerprint at {$path}");

                return self::FAILURE;
            }
            $before = json_decode((string) file_get_contents($path), true);
            unset($before['format']);
            $now = $fp;
            unset($now['format']);
            $diff = self::diff(self::normalize($before), self::normalize($now));
            if ($this->option('allow-additions')) {
                $added = array_values(array_filter($diff, fn ($l) => str_contains($l, ': missing before, now ')));
                $diff = array_values(array_diff($diff, $added));
                foreach ($added as $line) {
                    $this->line('  added (accepted): '.strtok($line, ':'));
                }
            }
            if ($diff === []) {
                $this->info('Reconciliation OK: fingerprint identical.');

                return self::SUCCESS;
            }
            $this->error(count($diff).' difference(s):');
            foreach (array_slice($diff, 0, 200) as $line) {
                $this->line("  {$line}");
            }

            return self::FAILURE;
        }

        if (! $this->option('output')) {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    /**
     * Natural keys of list-shaped invariant sections. Comparison keys rows by
     * them, so a NEW row (e.g. the LOT document prefix introduced by A5-B)
     * shows up as one addition instead of shifting every later index.
     * The written JSON format is unchanged (old baselines stay comparable).
     */
    private const ROW_KEYS = [
        'sales_by_status' => ['status'],
        'outstanding_by_customer' => ['customer_id'],
        'payments_valid_by_method' => ['method'],
        'stock_movements_by_type' => ['movement_type'],
        'stock_by_position' => ['product_id', 'node_id', 'bucket'],
        'goods_receipts_by_status' => ['status'],
        'purchase_orders_by_status' => ['status'],
        'document_sequences' => ['prefix'],
    ];

    /** @param array<string, mixed> $fp */
    private static function normalize(array $fp): array
    {
        foreach (self::ROW_KEYS as $section => $keys) {
            if (! isset($fp['invariants'][$section]) || ! array_is_list($fp['invariants'][$section])) {
                continue;
            }
            $keyed = [];
            foreach ($fp['invariants'][$section] as $row) {
                $keyed[implode('/', array_map(fn ($k) => (string) ($row[$k] ?? ''), $keys))] = $row;
            }
            ksort($keyed);
            $fp['invariants'][$section] = $keyed;
        }

        return $fp;
    }

    /** @return array<string, mixed> */
    private function invariants(ConnectionInterface $db): array
    {
        $one = fn (string $sql) => (array) $db->selectOne($sql);
        $all = fn (string $sql) => array_map(fn ($r) => (array) $r, $db->select($sql));

        return [
            'sales_by_status' => $all("SELECT status, count(*) AS n, sum(grand_total)::numeric(19,4)::text AS grand_total FROM sales GROUP BY status ORDER BY status"),
            'receivable' => $one("SELECT count(*) AS open_sales, COALESCE(sum(outstanding), 0)::numeric(19,4)::text AS outstanding,
                COALESCE(sum(grand_total - paid_amount - credited_amount) FILTER (WHERE status IN ('CONFIRMED','COMPLETED')), 0)::numeric(19,4)::text AS recomputed
                FROM sales WHERE status IN ('CONFIRMED','COMPLETED')"),
            'outstanding_by_customer' => $all("SELECT COALESCE(customer_id::text, 'WALK_IN') AS customer_id, sum(outstanding)::numeric(19,4)::text AS outstanding
                FROM sales WHERE outstanding > 0 GROUP BY customer_id ORDER BY 1"),
            'payments' => $one("SELECT count(*) AS n, COALESCE(sum(p.amount), 0)::numeric(19,4)::text AS gross,
                count(r.id) AS reversed_n, COALESCE(sum(p.amount) FILTER (WHERE r.id IS NOT NULL), 0)::numeric(19,4)::text AS reversed,
                COALESCE(sum(p.amount) FILTER (WHERE r.id IS NULL), 0)::numeric(19,4)::text AS valid
                FROM payments p LEFT JOIN payment_reversals r ON r.payment_id = p.id"),
            'payments_valid_by_method' => $all("SELECT p.method, sum(p.amount)::numeric(19,4)::text AS valid FROM payments p
                WHERE NOT EXISTS (SELECT 1 FROM payment_reversals r WHERE r.payment_id = p.id) GROUP BY p.method ORDER BY 1"),
            'customer_returns' => $one("SELECT count(*) AS n, COALESCE(sum(total_value), 0)::numeric(19,4)::text AS total_value,
                COALESCE(sum(credit_amount), 0)::numeric(19,4)::text AS credit, COALESCE(sum(refund_amount), 0)::numeric(19,4)::text AS refund FROM customer_returns"),
            'stock_movements_by_type' => $all("SELECT movement_type, count(*) AS n, sum(signed_quantity)::numeric(18,4)::text AS signed FROM stock_movements GROUP BY movement_type ORDER BY 1"),
            'stock_by_position' => $all("SELECT product_id::text, node_id::text, bucket, sum(signed_quantity)::numeric(18,4)::text AS quantity
                FROM stock_movements GROUP BY product_id, node_id, bucket ORDER BY 1, 2, 3"),
            'goods_receipts_by_status' => $all("SELECT g.status, count(DISTINCT g.id) AS n, COALESCE(sum(l.line_cost), 0)::numeric(19,4)::text AS cost
                FROM goods_receipts g LEFT JOIN goods_receipt_lines l ON l.goods_receipt_id = g.id GROUP BY g.status ORDER BY 1"),
            'purchase_orders_by_status' => $all('SELECT status, count(*) AS n FROM purchase_orders GROUP BY status ORDER BY 1'),
            'document_sequences' => $all("SELECT prefix, max(sequence_date)::text AS last_date, sum(last_value) AS issued FROM document_sequences GROUP BY prefix ORDER BY 1"),
            'audit' => $one("SELECT count(*) AS n, COALESCE(max(occurred_at)::text, '') AS last FROM audit_logs"),
        ] + ($this->hasTable($db, 'inventory_lots') ? ['lots' => $this->lotInvariants($db)] : []);
    }

    /** A5-B: lot identity, original quantities vs the movements that created them, remaining per lot, lineage. */
    private function lotInvariants(ConnectionInterface $db): array
    {
        $one = fn (string $sql) => (array) $db->selectOne($sql);
        $all = fn (string $sql) => array_map(fn ($r) => (array) $r, $db->select($sql));
        $ledger = "SELECT COALESCE(m.lot_id, dgway_legacy_lot(m.product_id, m.node_id)) AS lot_id, m.bucket, m.signed_quantity, m.quantity, m.movement_type FROM stock_movements m";

        return [
            'by_origin' => $all("SELECT origin, count(*) AS n, COALESCE(sum(original_quantity), 0)::numeric(18,4)::text AS original FROM inventory_lots GROUP BY origin ORDER BY 1"),
            'original_vs_created' => $one("SELECT COALESCE(sum(l.original_quantity), 0)::numeric(18,4)::text AS original,
                COALESCE((SELECT sum(m.quantity) FROM stock_movements m JOIN inventory_lots x ON x.id = m.lot_id
                   WHERE (x.origin, m.movement_type) IN (('RECEIPT','RECEIPT'), ('REGRADE','REGRADE_IN'), ('ADJUSTMENT','ADJUSTMENT_IN'))), 0)::numeric(18,4)::text AS created
                FROM inventory_lots l WHERE l.origin <> 'LEGACY'"),
            'remaining_by_origin_bucket' => $all("SELECT l.origin, x.bucket, sum(x.signed_quantity)::numeric(18,4)::text AS remaining
                FROM ({$ledger}) x JOIN inventory_lots l ON l.id = x.lot_id GROUP BY 1, 2 ORDER BY 1, 2"),
            'remaining_by_lot' => $one("SELECT count(*) AS positions, COALESCE(md5(string_agg(lot_id::text || '|' || bucket || '|' || q::text, E'\n' ORDER BY lot_id, bucket)), md5('')) AS md5
                FROM (SELECT lot_id, bucket, sum(signed_quantity)::numeric(18,4) AS q FROM ({$ledger}) x GROUP BY 1, 2 HAVING sum(signed_quantity) <> 0) y"),
            'movements_by_type' => $all("SELECT movement_type, count(*) AS n, sum(quantity)::numeric(18,4)::text AS quantity FROM ({$ledger}) x GROUP BY 1 ORDER BY 1"),
            'unattributed_movements' => $one('SELECT count(*) AS n FROM stock_movements WHERE lot_id IS NULL'),
            // A5-C upstream facts (only once the columns exist; older databases simply lack the key)
            ...($this->hasColumn($db, 'inventory_lots', 'collection_date') ? ['upstream' => $all("SELECT origin,
                count(*) FILTER (WHERE collection_date IS NOT NULL) AS with_collection_date,
                count(*) FILTER (WHERE supplier_reference IS NOT NULL) AS with_supplier_reference,
                COALESCE(md5(string_agg(number || '|' || COALESCE(collection_date::text, '-') || '|' || COALESCE(supplier_reference, '-'), E'\n' ORDER BY number)), md5('')) AS md5
                FROM inventory_lots GROUP BY origin ORDER BY origin")] : []),
            'lineage' => $one("SELECT count(*) AS edges, COALESCE(md5(string_agg(c.number || '<' || p.number, E'\n' ORDER BY c.number)), md5('')) AS md5
                FROM inventory_lots c JOIN inventory_lots p ON p.id = c.parent_lot_id"),
        ];
    }

    private function hasColumn(ConnectionInterface $db, string $table, string $column): bool
    {
        return $db->table('information_schema.columns')->where('table_schema', 'public')->where('table_name', $table)->where('column_name', $column)->exists();
    }

    private function hasTable(ConnectionInterface $db, string $table): bool
    {
        return (bool) $db->selectOne("SELECT to_regclass(?) IS NOT NULL AS e", ["public.{$table}"])->e;
    }

    /** @return array<string, array{rows: int, md5: string}> */
    private function checksums(ConnectionInterface $db): array
    {
        $out = [];
        foreach (config('data_contract.tables') as $table => $c) {
            if (($c['migration'] ?? '') !== 'preserve' || ! $this->hasTable($db, $table)) {
                continue; // a table introduced by a later migration is simply absent from an older database
            }
            $cols = implode(', ', array_map(fn ($col) => "COALESCE(\"{$col}\"::text, '\\N')", $c['fingerprint']));
            $row = $db->selectOne("SELECT count(*) AS n, COALESCE(md5(string_agg(concat_ws('|', {$cols}), E'\\n' ORDER BY id)), md5('')) AS h FROM \"{$table}\"");
            $out[$table] = ['rows' => (int) $row->n, 'md5' => (string) $row->h];
            if (! empty($c['fingerprint_added']) && $this->hasColumn($db, $table, $c['fingerprint_added'][0])) {
                $added = implode(', ', array_map(fn ($col) => "COALESCE(\"{$col}\"::text, '\\N')", ['id', ...$c['fingerprint_added']]));
                $row = $db->selectOne("SELECT count(*) AS n, COALESCE(md5(string_agg(concat_ws('|', {$added}), E'\\n' ORDER BY id)), md5('')) AS h FROM \"{$table}\"");
                $out["{$table}+added"] = ['rows' => (int) $row->n, 'md5' => (string) $row->h];
            }
            // later phases add their own group ("<table>+a5c", …) so earlier groups stay comparable
            foreach ($c['fingerprint_additions'] ?? [] as $phase => $columns) {
                if (! $this->hasColumn($db, $table, $columns[0])) {
                    continue;
                }
                $added = implode(', ', array_map(fn ($col) => "COALESCE(\"{$col}\"::text, '\\N')", ['id', ...$columns]));
                $row = $db->selectOne("SELECT count(*) AS n, COALESCE(md5(string_agg(concat_ws('|', {$added}), E'\\n' ORDER BY id)), md5('')) AS h FROM \"{$table}\"");
                $out["{$table}+{$phase}"] = ['rows' => (int) $row->n, 'md5' => (string) $row->h];
            }
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private static function diff(mixed $a, mixed $b, string $path = ''): array
    {
        if (is_array($a) && is_array($b)) {
            $out = [];
            foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
                $p = $path === '' ? (string) $k : "{$path}.{$k}";
                if (! array_key_exists($k, $a)) {
                    $out[] = "{$p}: missing before, now ".json_encode($b[$k]);
                } elseif (! array_key_exists($k, $b)) {
                    $out[] = "{$p}: was ".json_encode($a[$k]).', now missing';
                } else {
                    array_push($out, ...self::diff($a[$k], $b[$k], $p));
                }
            }

            return $out;
        }

        return $a == $b ? [] : ["{$path}: ".json_encode($a).' → '.json_encode($b)];
    }
}
