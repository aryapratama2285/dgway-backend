<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Identity\Role;
use App\Domain\Shared\Identity\Actor;
use InvalidArgumentException;

/**
 * Use case: create an operator account (run from the server console).
 * There is deliberately no public sign-up endpoint and no default password.
 */
final class RegisterOperator
{
    public function __construct(
        private readonly OperatorRegistry $registry,
        private readonly TransactionManager $transactions,
        private readonly AuditRecorder $audit,
        private readonly int $passwordMinLength,
    ) {
    }

    public function execute(Actor $actor, string $name, string $email, string $plainPassword, Role $role = Role::OWNER): OperatorAccount
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));

        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email is required.');
        }
        if (mb_strlen($plainPassword) < $this->passwordMinLength) {
            throw new InvalidArgumentException("Password must be at least {$this->passwordMinLength} characters.");
        }

        return $this->transactions->run(function () use ($actor, $name, $email, $plainPassword, $role): OperatorAccount {
            if ($this->registry->emailExists($email)) {
                throw new InvalidArgumentException("An operator with email {$email} already exists.");
            }

            $account = $this->registry->create($name, $email, $plainPassword, $role);

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'operator.registered',
                entityType: 'operator',
                entityId: $account->id,
                after: ['name' => $account->name, 'email' => $account->email, 'role' => $account->role->value],
            ));

            return $account;
        });
    }
}
