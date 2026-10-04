<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Network\BootstrapPrimaryNode;
use App\Domain\Shared\Identity\Actor;
use Illuminate\Console\Command;

/** Ensures the default operational node (DG-WAY-PRIMARY) exists. Idempotent. */
final class BootstrapNetworkCommand extends Command
{
    protected $signature = 'dgway:network:bootstrap';

    protected $description = 'Create the default DG Way node (and a generic site) if missing';

    public function handle(BootstrapPrimaryNode $bootstrap): int
    {
        $result = $bootstrap->execute(Actor::system('console'), (string) config('dgway.default_node_code'));
        $node = $result['node'];

        $this->info(($result['created'] ? 'Created' : 'Already exists').": node {$node->code()->value} ({$node->id()->value})");

        return self::SUCCESS;
    }
}
