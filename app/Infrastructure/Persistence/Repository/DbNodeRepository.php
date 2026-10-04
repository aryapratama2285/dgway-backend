<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Network\Node;
use App\Domain\Network\NodeCapability;
use App\Domain\Network\NodeRepository;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

final class DbNodeRepository implements NodeRepository
{
    private const TABLE = 'nodes';
    private const LABEL = 'Node';

    public function __construct(private readonly RepositorySupport $support)
    {
    }

    public function get(Id $id): Node
    {
        return self::hydrate($this->support->fetch(self::TABLE, $id->value, self::LABEL));
    }

    public function getForUpdate(Id $id): Node
    {
        return self::hydrate($this->support->fetch(self::TABLE, $id->value, self::LABEL, lock: true));
    }

    public function findByCode(BusinessCode $code): ?Node
    {
        $row = $this->support->db()->table(self::TABLE)->where('code', $code->value)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function add(Node $node): void
    {
        $this->support->insert(self::TABLE, self::row($node), $node, self::LABEL, 'nodes_code_unique', $node->code()->value);
    }

    public function save(Node $node): void
    {
        $this->support->update(self::TABLE, self::row($node), $node, self::LABEL);
    }

    public function countActiveAtLocation(Id $locationId): int
    {
        return $this->support->db()->table(self::TABLE)->where('location_id', $locationId->value)->where('is_active', true)->count();
    }

    public static function hydrate(object $r): Node
    {
        return Node::reconstitute(
            Id::fromString($r->id),
            (int) $r->version,
            BusinessCode::of($r->code),
            Name::of($r->name),
            $r->location_id === null ? null : Id::fromString($r->location_id),
            array_map(fn (string $c) => NodeCapability::from($c), self::parseArray((string) $r->capabilities)),
            $r->notes,
            (bool) $r->is_active,
        );
    }

    /** PostgreSQL text[] literal → list of strings (values are enum-safe identifiers). */
    public static function parseArray(string $literal): array
    {
        $inner = trim($literal, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }

    /** @return array<string, mixed> */
    private static function row(Node $n): array
    {
        return [
            'code' => $n->code()->value,
            'name' => $n->name()->value,
            'location_id' => $n->locationId()?->value,
            'capabilities' => '{'.implode(',', array_map(fn (NodeCapability $c) => $c->value, $n->capabilities())).'}',
            'notes' => $n->notes(),
            'is_active' => $n->isActive(),
        ];
    }
}
