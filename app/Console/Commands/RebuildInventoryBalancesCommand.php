<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Inventory\StockProjection;
use Illuminate\Console\Command;

/**
 * Recomputes stock_balances entirely from stock_movements. Safe to run at
 * any time: it locks the projection while rebuilding, so postings wait and
 * then continue against the rebuilt figures. The ledger is never touched.
 */
final class RebuildInventoryBalancesCommand extends Command
{
    protected $signature = 'dgway:inventory:rebuild-balances {--force : Do not ask for confirmation}';

    protected $description = 'Rebuild the stock_balances projection from the ledger';

    public function handle(StockProjection $projection): int
    {
        if (! $this->option('force') && ! $this->confirm('Rebuild stock balances from the ledger now?', true)) {
            return self::FAILURE;
        }

        $before = count($projection->verify());
        $written = $projection->rebuild();
        $after = count($projection->verify());
        $this->info("Rebuilt {$written} position(s). Mismatches before: {$before}, after: {$after}.");

        return $after === 0 ? self::SUCCESS : self::FAILURE;
    }
}
