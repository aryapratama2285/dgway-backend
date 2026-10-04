<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

final class CreateOperatorCommandTest extends DatabaseTestCase
{
    public function test_creates_owner_with_hashed_password_and_audit(): void
    {
        $this->artisan('dgway:operator:create', ['email' => 'Owner2@DGWay.test', '--name' => 'Owner Dua'])
            ->expectsQuestion('Password (min. 10 characters)', 'sangat-rahasia-1')
            ->expectsOutputToContain('Operator created: owner2@dgway.test (OWNER)')
            ->assertSuccessful();

        $user = DB::table('users')->where('email', 'owner2@dgway.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('sangat-rahasia-1', $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'operator.registered', 'entity_id' => $user->id, 'actor_label' => 'console']);
    }

    public function test_rejects_short_password(): void
    {
        $this->artisan('dgway:operator:create', ['email' => 'x@dgway.test', '--name' => 'X'])
            ->expectsQuestion('Password (min. 10 characters)', 'pendek')
            ->expectsOutputToContain('at least 10')
            ->assertFailed();

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_rejects_duplicate_email(): void
    {
        foreach ([0, 1] as $attempt) {
            $cmd = $this->artisan('dgway:operator:create', ['email' => 'dup@dgway.test', '--name' => 'Dup'])
                ->expectsQuestion('Password (min. 10 characters)', 'sangat-rahasia-1');
            $attempt === 0 ? $cmd->assertSuccessful() : $cmd->assertFailed();
            $cmd->run();
        }

        $this->assertSame(1, DB::table('users')->where('email', 'dup@dgway.test')->count());
    }
}
