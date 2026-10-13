<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\OperatorRegistry;
use Illuminate\Console\Command;

/** F5A: list operator accounts (no secrets). */
final class OperatorListCommand extends Command
{
    protected $signature = 'dgway:operator:list';

    protected $description = 'List DG Way operator accounts with role and status';

    public function handle(OperatorRegistry $registry): int
    {
        $rows = array_map(fn ($a) => [$a->email, $a->name, $a->role->value, $a->isActive ? 'active' : 'INACTIVE', $a->id], $registry->all());
        $this->table(['email', 'name', 'role', 'status', 'id'], $rows);

        return self::SUCCESS;
    }
}
