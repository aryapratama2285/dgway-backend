<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Shared\Quantity\Uom;
use Illuminate\Database\ConnectionInterface;

final class DbUnitOfMeasureRepository implements UnitOfMeasureRepository
{
    /** @var array<string, object|null> per-request cache of the tiny uoms table */
    private array $cache = [];

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function findActive(string $code): ?Uom
    {
        $row = $this->row($code);

        return $row !== null && $row->is_active ? Uom::of($row->code, (int) $row->precision) : null;
    }

    public function find(string $code): ?Uom
    {
        $row = $this->row($code);

        return $row === null ? null : Uom::of($row->code, (int) $row->precision);
    }

    private function row(string $code): ?object
    {
        $code = strtoupper(trim($code));
        if (! array_key_exists($code, $this->cache)) {
            $this->cache[$code] = $this->db->table('uoms')->where('code', $code)->first();
        }

        return $this->cache[$code];
    }
}
