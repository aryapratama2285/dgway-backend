<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

final class IdempotencyRetentionTest extends DatabaseTestCase
{
    public function test_prune_deletes_only_completed_keys_older_than_retention(): void
    {
        $user = User::factory()->create();
        foreach ([['old', 45], ['recent', 5]] as [$label, $ageDays]) {
            DB::table('idempotency_keys')->insert([
                'id' => (string) Str::uuid7(), 'actor_scope' => $user->id, 'actor_user_id' => $user->id,
                'idempotency_key' => "key-{$label}-0000000000000", 'command' => 'test', 'request_hash' => str_repeat('a', 64),
                'status' => 'COMPLETED', 'response_status' => 201, 'response_body' => '{}',
                'created_at' => now('UTC')->subDays($ageDays), 'completed_at' => now('UTC')->subDays($ageDays),
            ]);
        }

        $this->artisan('dgway:idempotency:prune', ['--dry-run' => true])->expectsOutputToContain('Would delete 1')->assertSuccessful();
        $this->assertSame(2, DB::table('idempotency_keys')->count());

        $this->artisan('dgway:idempotency:prune')->expectsOutputToContain('Deleted 1')->assertSuccessful();
        $this->assertSame(['key-recent-0000000000000'], DB::table('idempotency_keys')->pluck('idempotency_key')->all());
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('dgway:idempotency:prune')->assertSuccessful();
    }
}
