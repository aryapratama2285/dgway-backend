<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\Node;
use App\Domain\Network\NodeCapability;
use App\Domain\Network\NodeRepository;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Identity\Actor;

/**
 * Creates the default operational node (decision D12: DG-WAY-PRIMARY) and a
 * generic site for it, if they do not exist yet. Safe to run repeatedly.
 * Physical address details are added later with UpdateLocation.
 */
final class BootstrapPrimaryNode
{
    public const SITE_CODE = 'DG-WAY-PRIMARY-SITE';

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly CreateLocation $createLocation,
        private readonly CreateNode $createNode,
        private readonly TransactionManager $tx,
    ) {
    }

    /** @return array{node: Node, created: bool} */
    public function execute(Actor $actor, string $nodeCode): array
    {
        return $this->tx->run(function () use ($actor, $nodeCode): array {
            $existing = $this->nodes->findByCode(BusinessCode::of($nodeCode));
            if ($existing !== null) {
                return ['node' => $existing, 'created' => false];
            }

            $site = $this->createLocation->execute($actor, self::SITE_CODE, 'DG Way Primary Site', null, 'Generic primary site. Add the physical address when known.');
            $node = $this->createNode->execute(
                $actor,
                $nodeCode,
                'DG Way Primary Node',
                $site->id()->value,
                array_map(fn (NodeCapability $c) => $c->value, NodeCapability::cases()),
            );

            return ['node' => $node, 'created' => true];
        });
    }
}
