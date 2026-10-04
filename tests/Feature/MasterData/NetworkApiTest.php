<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use Illuminate\Support\Facades\DB;

final class NetworkApiTest extends MasterDataTestCase
{
    private function location(string $code = 'DELTAMAS'): string
    {
        return $this->cmd('POST', 'locations', [
            'code' => $code, 'name' => 'DG Way Deltamas',
            'address' => ['street' => 'Jl. Deltamas Raya', 'city' => 'Kabupaten Bekasi'],
        ])->assertCreated()->json('data.id');
    }

    private function node(?string $locationId, string $code = 'DG-WAY-PRIMARY', array $caps = ['INVENTORY', 'RECEIVING', 'DELIVERY']): string
    {
        return $this->cmd('POST', 'nodes', ['code' => $code, 'name' => $code, 'location_id' => $locationId, 'capabilities' => $caps])
            ->assertCreated()->json('data.id');
    }

    public function test_location_and_node_are_separate_resources(): void
    {
        $loc = $this->location();
        $node = $this->node($loc);

        $this->get_("locations/{$loc}")->assertOk()->assertJsonPath('data.address.city', 'Kabupaten Bekasi');
        $this->get_("nodes/{$node}")->assertOk()
            ->assertJsonPath('data.code', 'DG-WAY-PRIMARY')
            ->assertJsonPath('data.location_id', $loc)
            ->assertJsonPath('data.capabilities', ['INVENTORY', 'RECEIVING', 'DELIVERY']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'location.created', 'entity_id' => $loc]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'node.created', 'entity_id' => $node]);
    }

    public function test_node_without_location_and_capability_filter(): void
    {
        $this->node($this->location(), 'DG-WAY-PRIMARY', ['INVENTORY', 'FULFILLMENT']);
        $this->node(null, 'VAN-01', ['DELIVERY']);

        $this->assertSame(['VAN-01'], array_column($this->get_('nodes?capability=DELIVERY')->json('data'), 'code'));
        $this->assertSame(['DG-WAY-PRIMARY'], array_column($this->get_('nodes?capability=INVENTORY')->json('data'), 'code'));
    }

    public function test_capability_validation(): void
    {
        $this->cmd('POST', 'nodes', ['code' => 'N1', 'name' => 'N1', 'capabilities' => ['TELEPORT']])
            ->assertStatus(422)->assertJsonPath('error.code', 'NODE_CAPABILITY_INVALID');
        $this->cmd('POST', 'nodes', ['code' => 'N2', 'name' => 'N2', 'capabilities' => []])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_update_node_capabilities_and_relocate(): void
    {
        $loc1 = $this->location('DELTAMAS');
        $loc2 = $this->location('CIKARANG');
        $node = $this->node($loc1);

        $this->cmd('PATCH', "nodes/{$node}", ['capabilities' => ['delivery', 'inventory'], 'location_id' => $loc2])
            ->assertOk()
            ->assertJsonPath('data.capabilities', ['INVENTORY', 'DELIVERY'])
            ->assertJsonPath('data.location_id', $loc2);
        $this->cmd('PATCH', "nodes/{$node}", ['capabilities' => []])->assertStatus(422)->assertJsonPath('error.code', 'NODE_CAPABILITY_REQUIRED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'node.updated', 'entity_id' => $node]);
    }

    public function test_location_with_active_node_cannot_be_deactivated(): void
    {
        $loc = $this->location();
        $node = $this->node($loc);

        $this->cmd('POST', "locations/{$loc}/deactivate")->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_HAS_ACTIVE_NODES');

        $this->cmd('POST', "nodes/{$node}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->cmd('POST', "locations/{$loc}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        // node cannot come back while its location is inactive; history stays readable
        $this->cmd('POST', "nodes/{$node}/activate")->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_INACTIVE');
        $this->get_("nodes/{$node}")->assertOk()->assertJsonPath('data.location_id', $loc);
        // and no new node can be placed there
        $this->cmd('POST', 'nodes', ['code' => 'N3', 'name' => 'N3', 'location_id' => $loc, 'capabilities' => ['PICKUP']])
            ->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_INACTIVE');
    }

    public function test_duplicate_node_code_and_retry(): void
    {
        $key = '5a1e0000-aaaa-4bbb-8ccc-000000000003';
        $body = ['code' => 'DG-WAY-PRIMARY', 'name' => 'Primary', 'capabilities' => ['INVENTORY']];

        $this->cmd('POST', 'nodes', $body, $key)->assertCreated();
        $this->cmd('POST', 'nodes', $body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->cmd('POST', 'nodes', $body)->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_CODE');
        $this->assertSame(1, DB::table('nodes')->count());
    }

    public function test_bootstrap_command_creates_primary_node_once(): void
    {
        $this->artisan('dgway:network:bootstrap')->expectsOutputToContain('Created: node DG-WAY-PRIMARY')->assertSuccessful();
        $this->artisan('dgway:network:bootstrap')->expectsOutputToContain('Already exists: node DG-WAY-PRIMARY')->assertSuccessful();

        $node = DB::table('nodes')->where('code', 'DG-WAY-PRIMARY')->first();
        $this->assertSame('{INVENTORY,RECEIVING,FULFILLMENT,PICKUP,DELIVERY}', $node->capabilities);
        $this->assertSame('DG-WAY-PRIMARY-SITE', DB::table('locations')->where('id', $node->location_id)->value('code'));
        $this->assertSame(1, DB::table('nodes')->count());
    }
}
