<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Shared\Text\Notes;
use App\Domain\Shared\Address\Address;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Text\Name;

/**
 * A physical place (e.g. "DG Way Deltamas"): where things physically are.
 *
 * A Location is NOT an operational unit; that is a Node. One location can
 * host several nodes (a stock room and a pickup counter), and a node can
 * exist without a fixed location (a delivery vehicle).
 */
final class Location extends AggregateRoot
{
    private function __construct(
        Id $id,
        int $version,
        private readonly BusinessCode $code,
        private Name $name,
        private ?Address $address,
        private ?string $notes,
        private bool $active,
    ) {
        parent::__construct($id, $version);
    }

    public static function create(Id $id, BusinessCode $code, Name $name, ?Address $address = null, ?string $notes = null): self
    {
        return new self($id, 0, $code, $name, $address, Notes::clean($notes), true);
    }

    public static function reconstitute(Id $id, int $version, BusinessCode $code, Name $name, ?Address $address, ?string $notes, bool $active): self
    {
        return new self($id, $version, $code, $name, $address, $notes, $active);
    }

    public function rename(Name $name): bool
    {
        if ($this->name->equals($name)) {
            return false;
        }
        $this->name = $name;

        return true;
    }

    public function relocate(?Address $address): bool
    {
        if ($this->address === null && $address === null) {
            return false;
        }
        if ($this->address !== null && $address !== null && $this->address->equals($address)) {
            return false;
        }
        $this->address = $address;

        return true;
    }

    public function changeNotes(?string $notes): bool
    {
        $notes = Notes::clean($notes);
        if ($this->notes === $notes) {
            return false;
        }
        $this->notes = $notes;

        return true;
    }

    public function activate(): bool
    {
        if ($this->active) {
            return false;
        }
        $this->active = true;

        return true;
    }

    /** A location that still hosts active nodes cannot be deactivated. */
    public function deactivate(int $activeNodesHere): bool
    {
        if (! $this->active) {
            return false;
        }
        if ($activeNodesHere > 0) {
            throw InvariantViolation::because('LOCATION_HAS_ACTIVE_NODES', 'Deactivate or move the active nodes at this location first.', ['active_nodes' => $activeNodesHere]);
        }
        $this->active = false;

        return true;
    }

    public function code(): BusinessCode
    {
        return $this->code;
    }

    public function name(): Name
    {
        return $this->name;
    }

    public function address(): ?Address
    {
        return $this->address;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function snapshot(): array
    {
        return [
            'code' => $this->code->value,
            'name' => $this->name->value,
            'address' => $this->address?->jsonSerialize(),
            'notes' => $this->notes,
            'is_active' => $this->active,
        ];
    }
}
