<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Shared\Text\Notes;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Text\Name;

/**
 * An operational unit of the DG Way network (e.g. DG-WAY-PRIMARY) with a set
 * of capabilities. Inventory, receiving and fulfillment (A3+) reference a
 * Node, never a Location directly, so the physical place can change without
 * rewriting stock history.
 */
final class Node extends AggregateRoot
{
    /** @var list<NodeCapability> */
    private array $capabilities;

    /** @param list<NodeCapability> $capabilities */
    private function __construct(
        Id $id,
        int $version,
        private readonly BusinessCode $code,
        private Name $name,
        private ?Id $locationId,
        array $capabilities,
        private ?string $notes,
        private bool $active,
    ) {
        parent::__construct($id, $version);
        $this->capabilities = self::normalizeCapabilities($capabilities);
    }

    /** @param list<NodeCapability> $capabilities */
    public static function create(Id $id, BusinessCode $code, Name $name, ?Location $location, array $capabilities, ?string $notes = null): self
    {
        if ($location !== null) {
            self::assertLocationUsable($location);
        }

        return new self($id, 0, $code, $name, $location?->id(), $capabilities, Notes::clean($notes), true);
    }

    /** @param list<NodeCapability> $capabilities */
    public static function reconstitute(Id $id, int $version, BusinessCode $code, Name $name, ?Id $locationId, array $capabilities, ?string $notes, bool $active): self
    {
        return new self($id, $version, $code, $name, $locationId, $capabilities, $notes, $active);
    }

    public function rename(Name $name): bool
    {
        if ($this->name->equals($name)) {
            return false;
        }
        $this->name = $name;

        return true;
    }

    /** Move the node to another physical location, or detach it (null). */
    public function relocateTo(?Location $location): bool
    {
        if ($location === null) {
            $changed = $this->locationId !== null;
            $this->locationId = null;

            return $changed;
        }
        if ($this->locationId !== null && $this->locationId->equals($location->id())) {
            return false;
        }
        self::assertLocationUsable($location);
        $this->locationId = $location->id();

        return true;
    }

    /** @param list<NodeCapability> $capabilities */
    public function redefineCapabilities(array $capabilities): bool
    {
        $new = self::normalizeCapabilities($capabilities);
        if ($new === $this->capabilities) {
            return false;
        }
        $this->capabilities = $new;

        return true;
    }

    public function grant(NodeCapability $capability): bool
    {
        return $this->redefineCapabilities([...$this->capabilities, $capability]);
    }

    public function revoke(NodeCapability $capability): bool
    {
        return $this->redefineCapabilities(array_values(array_filter($this->capabilities, fn (NodeCapability $c) => $c !== $capability)));
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

    /** Re-activating a node requires its location (if any) to be active. */
    public function activate(?Location $currentLocation): bool
    {
        if ($this->active) {
            return false;
        }
        if ($currentLocation !== null) {
            self::assertLocationUsable($currentLocation);
        }
        $this->active = true;

        return true;
    }

    /**
     * Stops new operations at this node. Its stock and transaction history
     * remain referenced. (From A3 on, deactivating a node that still holds
     * stock must be prevented; that check lands with the inventory ledger.)
     */
    public function deactivate(): bool
    {
        if (! $this->active) {
            return false;
        }
        $this->active = false;

        return true;
    }

    public function can(NodeCapability $capability): bool
    {
        return $this->active && in_array($capability, $this->capabilities, true);
    }

    public function code(): BusinessCode
    {
        return $this->code;
    }

    public function name(): Name
    {
        return $this->name;
    }

    public function locationId(): ?Id
    {
        return $this->locationId;
    }

    /** @return list<NodeCapability> */
    public function capabilities(): array
    {
        return $this->capabilities;
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
            'location_id' => $this->locationId?->value,
            'capabilities' => array_map(fn (NodeCapability $c) => $c->value, $this->capabilities),
            'notes' => $this->notes,
            'is_active' => $this->active,
        ];
    }

    /**
     * @param  list<NodeCapability>  $capabilities
     * @return list<NodeCapability>  unique, in canonical (enum declaration) order
     */
    private static function normalizeCapabilities(array $capabilities): array
    {
        $present = array_map(fn (NodeCapability $c) => $c->value, $capabilities);
        $result = array_values(array_filter(NodeCapability::cases(), fn (NodeCapability $c) => in_array($c->value, $present, true)));

        if ($result === []) {
            throw InvariantViolation::because('NODE_CAPABILITY_REQUIRED', 'A node needs at least one capability.');
        }

        return $result;
    }

    private static function assertLocationUsable(Location $location): void
    {
        if (! $location->isActive()) {
            throw InvariantViolation::because('LOCATION_INACTIVE', "Location {$location->code()->value} is inactive.");
        }
    }
}
