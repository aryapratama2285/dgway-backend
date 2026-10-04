<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Network;

use App\Domain\Network\Location;
use App\Domain\Network\Node;
use App\Domain\Network\NodeCapability as Cap;
use App\Domain\Shared\Address\Address;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;
use PHPUnit\Framework\TestCase;

final class NetworkTest extends TestCase
{
    private function location(string $code = 'DELTAMAS', string $id = '01928a3c-0000-7000-8000-000000000201'): Location
    {
        return Location::create(Id::fromString($id), BusinessCode::of($code), Name::of('DG Way Deltamas'), Address::of('Jl. Deltamas Raya'));
    }

    private function node(?Location $location, array $caps = [Cap::INVENTORY, Cap::DELIVERY]): Node
    {
        return Node::create(Id::fromString('01928a3c-0000-7000-8000-000000000301'), BusinessCode::of('DG-WAY-PRIMARY'), Name::of('DG Way Primary'), $location, $caps);
    }

    public function test_location_and_node_are_distinct_concepts(): void
    {
        $loc = $this->location();
        $node = $this->node($loc);

        $this->assertTrue($node->locationId()?->equals($loc->id()));
        $this->assertSame('DG-WAY-PRIMARY', $node->code()->value);
        $this->assertSame('DELTAMAS', $loc->code()->value);
    }

    public function test_node_without_fixed_location_is_allowed(): void
    {
        $vehicle = $this->node(null, [Cap::DELIVERY]);

        $this->assertNull($vehicle->locationId());
        $this->assertTrue($vehicle->can(Cap::DELIVERY));
        $this->assertFalse($vehicle->can(Cap::INVENTORY));
    }

    public function test_capabilities_are_unique_and_canonically_ordered(): void
    {
        $node = $this->node(null, [Cap::DELIVERY, Cap::INVENTORY, Cap::DELIVERY]);

        $this->assertSame([Cap::INVENTORY, Cap::DELIVERY], $node->capabilities());
        $this->assertFalse($node->redefineCapabilities([Cap::INVENTORY, Cap::DELIVERY]), 'same set, any order → no change');
    }

    public function test_node_needs_at_least_one_capability(): void
    {
        $node = $this->node(null, [Cap::DELIVERY]);

        $this->expectExceptionMessage('at least one capability');
        $node->revoke(Cap::DELIVERY);
    }

    public function test_grant_and_revoke(): void
    {
        $node = $this->node(null, [Cap::INVENTORY]);

        $this->assertTrue($node->grant(Cap::PICKUP));
        $this->assertFalse($node->grant(Cap::PICKUP));
        $this->assertTrue($node->revoke(Cap::INVENTORY));
        $this->assertSame([Cap::PICKUP], $node->capabilities());
    }

    public function test_node_cannot_be_placed_at_inactive_location(): void
    {
        $loc = $this->location();
        $loc->deactivate(0);

        $this->expectExceptionMessage('inactive');
        $this->node($loc);
    }

    public function test_location_with_active_nodes_cannot_be_deactivated(): void
    {
        $loc = $this->location();

        try {
            $loc->deactivate(activeNodesHere: 1);
            $this->fail();
        } catch (InvariantViolation $e) {
            $this->assertSame('LOCATION_HAS_ACTIVE_NODES', $e->errorCode());
        }
        $this->assertTrue($loc->isActive());
    }

    public function test_inactive_node_cannot_do_anything(): void
    {
        $node = $this->node(null, [Cap::INVENTORY]);
        $node->deactivate();

        $this->assertFalse($node->can(Cap::INVENTORY));
    }

    public function test_reactivating_node_requires_active_location(): void
    {
        $loc = $this->location();
        $node = $this->node($loc);
        $node->deactivate();
        $loc->deactivate(0);

        $this->expectExceptionMessage('inactive');
        $node->activate($loc);
    }

    public function test_relocate_node(): void
    {
        $node = $this->node($this->location());
        $other = $this->location('CIKARANG', '01928a3c-0000-7000-8000-000000000202');

        $this->assertTrue($node->relocateTo($other));
        $this->assertFalse($node->relocateTo($other));
        $this->assertTrue($node->relocateTo(null));
        $this->assertNull($node->locationId());
    }
}
