<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/** A5-B §20 — first-run bootstrap: idempotent, never a default account or password. */
final class BootstrapCommandTest extends DatabaseTestCase
{
    private function counts(): array
    {
        return [DB::table('nodes')->count(), DB::table('locations')->count(), DB::table('business_partners')->count(), DB::table('users')->count(), DB::table('audit_logs')->count()];
    }

    public function test_bootstrap_without_owner_creates_node_and_walk_in_but_never_an_account(): void
    {
        $this->artisan('dgway:bootstrap')->expectsOutputToContain('No operator exists')->assertSuccessful();
        [$nodes, $locations, $partners, $users] = $this->counts();
        $this->assertSame([1, 1, 1, 0], [$nodes, $locations, $partners, $users], 'no default operator account');

        $walkIn = DB::table('business_partners')->where('code', 'WALK-IN')->first();
        $role = DB::table('business_partner_roles')->where('partner_id', $walkIn->id)->first();
        $this->assertSame(['CUSTOMER', 'WALK_IN', 0, null], [$role->role, $role->customer_type, (int) $role->payment_term_days, $role->credit_limit]);
        $this->assertSame(1, DB::table('nodes')->where('code', 'DG-WAY-PRIMARY')->count());

        $before = $this->counts();
        $this->artisan('dgway:bootstrap')->assertSuccessful();
        $this->assertSame($before, $this->counts(), 'second run changes nothing (not even the audit log)');
    }

    public function test_owner_is_created_only_with_a_supplied_password_and_never_overwritten(): void
    {
        $this->artisan('dgway:bootstrap', ['--owner-email' => 'owner@dgway.test', '--owner-name' => 'Owner Satu'])
            ->expectsQuestion('Owner password (min. 10 characters)', 'sangat-rahasia-1')->assertSuccessful();
        $user = DB::table('users')->where('email', 'owner@dgway.test')->sole();
        $this->assertSame('OWNER', $user->role);
        $this->assertTrue(Hash::check('sangat-rahasia-1', $user->password));

        // re-run: exists, the password is NOT changed
        $this->artisan('dgway:bootstrap', ['--owner-email' => 'owner@dgway.test'])
            ->expectsQuestion('Owner password (min. 10 characters)', 'lain-lagi-sekali-2')->expectsOutputToContain('password NOT changed')->assertSuccessful();
        $this->assertTrue(Hash::check('sangat-rahasia-1', (string) DB::table('users')->where('email', 'owner@dgway.test')->value('password')));

        // empty or weak password is refused
        $this->artisan('dgway:bootstrap', ['--owner-email' => 'kosong@dgway.test'])->expectsQuestion('Owner password (min. 10 characters)', '')->assertFailed();
        $this->artisan('dgway:bootstrap', ['--owner-email' => 'pendek@dgway.test'])->expectsQuestion('Owner password (min. 10 characters)', 'pendek')->assertFailed();
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('nodes')->count());
    }

    public function test_password_can_be_piped_through_stdin_in_a_real_process(): void
    {
        $proc = proc_open([PHP_BINARY, base_path('artisan'), 'dgway:bootstrap', '--owner-email=pipe@dgway.test', '--password-stdin', '--no-ansi'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), $_ENV));
        fwrite($pipes[0], "rahasia-dari-stdin-9\n");
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc), $out);
        $this->assertTrue(Hash::check('rahasia-dari-stdin-9', (string) DB::table('users')->where('email', 'pipe@dgway.test')->value('password')));
    }
}
