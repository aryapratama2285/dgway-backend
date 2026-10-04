<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Operations\BootstrapOperations;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\Identity\Actor;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Idempotent first-run setup (A5-B §20):
 *
 *   php artisan dgway:bootstrap
 *   printf '%s' "$OWNER_PASSWORD" | php artisan dgway:bootstrap --owner-email=owner@dgway.id --owner-name="Owner" --password-stdin
 *
 * The password is never an argument (shell history) and never defaulted.
 */
final class BootstrapCommand extends Command
{
    protected $signature = 'dgway:bootstrap
        {--owner-email= : Create this OWNER operator if it does not exist}
        {--owner-name= : Display name for the owner}
        {--password-stdin : Read the owner password from STDIN instead of prompting}';

    protected $description = 'Prepare an installation: units, default node, walk-in customer, optional owner (idempotent, no default password)';

    public function handle(BootstrapOperations $bootstrap): int
    {
        $email = $this->option('owner-email') ?: null;
        $password = null;
        if ($email !== null) {
            $password = $this->option('password-stdin')
                ? rtrim((string) stream_get_contents(STDIN), "\r\n")
                : (string) $this->secret('Owner password (min. '.config('dgway.password_min_length').' characters)');
        }

        try {
            $report = $bootstrap->execute(Actor::system('bootstrap'), (string) config('dgway.default_node_code'), (string) config('dgway.walk_in_customer_code'),
                $email, $this->option('owner-name') ?: null, $password);
        } catch (DomainException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['step', 'status', 'detail'], $report);
        $this->line('Operational defaults: currency='.config('dgway.currency').' timezone='.config('dgway.business_timezone')
            .' default_node='.config('dgway.default_node_code').' walk_in='.config('dgway.walk_in_customer_code').' page_size='.config('dgway.default_page_size').' (served by GET /api/v1/config)');

        return self::SUCCESS;
    }
}
