<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\RegisterOperator;
use App\Domain\Shared\Identity\Actor;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Creates an operator account from the server console.
 * The password is prompted (hidden) or read from STDIN, never passed as an
 * argument, so it does not end up in shell history.
 */
final class CreateOperatorCommand extends Command
{
    protected $signature = 'dgway:operator:create
        {email : Operator email (login name)}
        {--name= : Display name}
        {--password-stdin : Read the password from STDIN instead of prompting}';

    protected $description = 'Create a DG Way owner/operator account';

    public function handle(RegisterOperator $register): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Name'));
        $password = $this->option('password-stdin')
            ? rtrim((string) stream_get_contents(STDIN), "\r\n")
            : (string) $this->secret('Password (min. '.config('dgway.password_min_length').' characters)');

        try {
            $account = $register->execute(Actor::system('console'), $name, (string) $this->argument('email'), $password);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Operator created: {$account->email} ({$account->role->value}) id={$account->id}");

        return self::SUCCESS;
    }
}
