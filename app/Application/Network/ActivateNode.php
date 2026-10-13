<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\LocationRepository;
use App\Domain\Network\Node;
use App\Domain\Network\NodeRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

final class ActivateNode
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly LocationRepository $locations,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $nodeId): Node
    {
        $this->authorizer->authorizeIfOperator($actor, Permission::MANAGE_NETWORK);

        return $this->tx->run(function () use ($actor, $nodeId): Node {
            $node = $this->nodes->getForUpdate(Id::fromString($nodeId));
            $location = $node->locationId() === null ? null : $this->locations->get($node->locationId());
            $before = $node->snapshot();
            if ($node->activate($location)) {
                $this->nodes->save($node);
                $this->audit->changed($actor, 'node.activated', 'node', $node, $before);
            }

            return $node;
        });
    }
}
