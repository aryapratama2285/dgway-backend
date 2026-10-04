<?php

declare(strict_types=1);

namespace App\Domain\Shared\Identity;

use App\Domain\Shared\Exception\InvariantViolation;

/**
 * Who performed an action. Every audited transaction carries an Actor.
 *
 * An actor is either an authenticated operator (user UUID) or the system
 * itself (migrations, scheduled jobs). There is no anonymous actor.
 */
final class Actor
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(
        public readonly ?string $userId,
        public readonly string $label,
    ) {
    }

    public static function operator(string $userId, string $label): self
    {
        $userId = strtolower($userId);
        if (preg_match(self::UUID_PATTERN, $userId) !== 1) {
            throw InvariantViolation::because('ACTOR_INVALID', 'Operator id must be a UUID.');
        }

        return new self($userId, $label);
    }

    public static function system(string $label = 'system'): self
    {
        return new self(null, $label);
    }

    public function isSystem(): bool
    {
        return $this->userId === null;
    }
}
