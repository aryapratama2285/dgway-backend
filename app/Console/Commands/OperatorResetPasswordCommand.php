<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\AdministerOperator;
use App\Domain\Shared\Identity\Actor;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * F5A: set a new password for a forgotten account. The password is prompted
 * (hidden) or read from STDIN, never an argument. All device tokens of the
 * operator are revoked; they sign in again with the new password.
 */
final class OperatorResetPasswordCommand extends Command
{
    protected $signature = 'dgway:operator:reset-password {email} {--password-stdin : Read the password from STDIN instead of prompting}';

    protected $description = 'Reset a DG Way operator password and sign the operator out everywhere';

    public function handle(AdministerOperator $admin): int
    {
        $password = $this->option('password-stdin')
            ? rtrim((string) stream_get_contents(STDIN), "\r\n")
            : (string) $this->secret('New password (min. '.config('dgway.password_min_length').' characters)');
        try {
            $revoked = $admin->resetPassword(Actor::system('console'), (string) $this->argument('email'), $password);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Password reset; {$revoked} device session(s) revoked.");

        return self::SUCCESS;
    }
}
