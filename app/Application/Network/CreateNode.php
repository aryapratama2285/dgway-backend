<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\LocationRepository;
use App\Domain\Network\Node;
use App\Domain\Network\NodeCapability;
use App\Domain\Network\NodeRepository;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

final class CreateNode
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly LocationRepository $locations,
        private readonly IdentityGenerator $ids,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    /** @param list<string> $capabilities */
    public function execute(Actor $actor, string $code, string $name, ?string $locationId, array $capabilities, ?string $notes = null): Node
    {
        return $this->tx->run(function () use ($actor, $code, $name, $locationId, $capabilities, $notes): Node {
            $node = Node::create(
                $this->ids->next(),
                BusinessCode::of($code, 'Node code'),
                Name::of($name),
                $locationId === null ? null : $this->locations->get(Id::fromString($locationId)),
                self::capabilities($capabilities),
                $notes,
            );
            $this->nodes->add($node);
            $this->audit->created($actor, 'node.created', 'node', $node);

            return $node;
        });
    }

    /**
     * @param  list<string>  $raw
     * @return list<NodeCapability>
     */
    public static function capabilities(array $raw): array
    {
        return array_map(
            fn ($c) => NodeCapability::tryFrom(strtoupper((string) $c))
                ?? throw InvariantViolation::because('NODE_CAPABILITY_INVALID', "Unknown node capability '{$c}'.", ['field' => 'capabilities']),
            array_values($raw),
        );
    }
}
