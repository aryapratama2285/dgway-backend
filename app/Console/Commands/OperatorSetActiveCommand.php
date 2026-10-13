<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\AdministerOperator;
use App\Domain\Shared\Identity\Actor;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * F5A: activate or deactivate an operator. Deactivation also revokes every
 * device token (a lost phone or a leaver is cut off immediately).
 *
 *   php artisan dgway:operator:deactivate staff@example.com
 *   php artisan dgway:operator:deactivate staff@example.com --activate
 */
final class OperatorSetActiveCommand extends Command
{
    protected $signature = 'dgway:operator:deactivate {email} {--activate : Re-activate instead}';

    protected $description = 'Deactivate (and sign out everywhere) or re-activate a DG Way operator';

    public function handle(AdministerOperator $admin): int
    {
        $activate = (bool) $this->option('activate');
        try {
            $revoked = $admin->setActive(Actor::system('console'), (string) $this->argument('email'), $activate);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info($activate ? 'Operator activated.' : "Operator deactivated; {$revoked} device session(s) revoked.");

        return self::SUCCESS;
    }
}
