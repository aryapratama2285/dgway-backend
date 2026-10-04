<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Inventory\StockProjection;
use Illuminate\Console\Command;

/**
 * Compares the stock_balances projection with the ledger (source of truth).
 * Exit code 1 when any position differs, so it can run in cron / monitoring.
 */
final class VerifyInventoryCommand extends Command
{
    protected $signature = 'dgway:inventory:verify';

    protected $description = 'Verify that stock_balances matches the sum of stock_movements';

    public function handle(StockProjection $projection): int
    {
        $mismatches = $projection->verify();
        if ($mismatches === []) {
            $this->info('Inventory projection is consistent with the ledger.');

            return self::SUCCESS;
        }

        $this->error(count($mismatches).' position(s) differ from the ledger:');
        $this->table(['product_id', 'node_id', 'bucket', 'projected', 'ledger'], $mismatches);
        $this->line('Run "php artisan dgway:inventory:rebuild-balances" to recompute from the ledger.');

        return self::FAILURE;
    }
}
