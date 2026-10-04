<?php

declare(strict_types=1);

namespace App\Application\Identity;

/** Port to the operator account store and its password hashes. */
interface OperatorDirectory
{
    /**
     * Returns the account only if the email exists AND the password matches
     * the stored hash. Never reveals which of the two was wrong.
     */
    public function verifyCredentials(string $email, string $password): ?OperatorAccount;

    public function find(string $operatorId): ?OperatorAccount;

    public function markLoggedIn(string $operatorId): void;
}
