<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\DatabaseTestCase;

abstract class MasterDataTestCase extends DatabaseTestCase
{
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Owner Satu']);
        Sanctum::actingAs($this->owner, ['operate']);
    }

    /** Sends a command with an Idempotency-Key (a fresh one unless given). */
    protected function cmd(string $method, string $uri, array $data = [], ?string $key = null): TestResponse
    {
        return $this->json($method, '/api/v1/'.ltrim($uri, '/'), $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    protected function get_(string $uri): TestResponse
    {
        return $this->getJson('/api/v1/'.ltrim($uri, '/'));
    }

    protected function category(string $code = 'EGG', ?string $parentId = null): string
    {
        return $this->cmd('POST', 'categories', ['code' => $code, 'name' => $code, 'parent_id' => $parentId])->assertCreated()->json('data.id');
    }

    protected function product(string $sku = 'TLR-A', ?string $categoryId = null, string $uom = 'KG'): string
    {
        return $this->cmd('POST', 'products', [
            'sku' => $sku, 'name' => "Produk {$sku}", 'category_id' => $categoryId ?? $this->category(), 'base_uom' => $uom,
        ])->assertCreated()->json('data.id');
    }
}
