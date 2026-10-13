<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Command\Patch;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\LocationRepository;
use App\Domain\Network\Node;
use App\Domain\Network\NodeRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

/** Rename, relocate, redefine capabilities, change notes (code is immutable). */
final class UpdateNode
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly LocationRepository $locations,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $nodeId, Patch $patch, ?int $expectedVersion = null): Node
    {
        $this->authorizer->authorizeIfOperator($actor, Permission::MANAGE_NETWORK);

        return $this->tx->run(function () use ($actor, $nodeId, $patch, $expectedVersion): Node {
            $node = $this->nodes->getForUpdate(Id::fromString($nodeId));
            ExpectedVersion::check('Node', $node, $expectedVersion);
            $before = $node->snapshot();
            $changed = false;

            if ($patch->has('name')) {
                $changed = $node->rename(Name::of((string) $patch->string('name'))) || $changed;
            }
            if ($patch->has('location_id')) {
                $locationId = $patch->string('location_id');
                $changed = $node->relocateTo($locationId === null ? null : $this->locations->get(Id::fromString($locationId))) || $changed;
            }
            if ($patch->has('capabilities')) {
                $changed = $node->redefineCapabilities(CreateNode::capabilities((array) $patch->get('capabilities'))) || $changed;
            }
            if ($patch->has('notes')) {
                $changed = $node->changeNotes($patch->string('notes')) || $changed;
            }

            if ($changed) {
                $this->nodes->save($node);
                $this->audit->changed($actor, 'node.updated', 'node', $node, $before);
            }

            return $node;
        });
    }
}
