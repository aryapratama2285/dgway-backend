<?php

declare(strict_types=1);

namespace App\Application\Shared\Audit;

/**
 * Request-level context merged into every audit entry written while a scope
 * is open (e.g. the Idempotency-Key and command name of the client request).
 * Lets an investigator go from an audit row back to the exact request.
 * One instance per process (container singleton); scopes nest.
 */
final class AuditScope
{
    /** @var list<array<string, string>> */
    private array $stack = [];

    /**
     * @template T
     *
     * @param  array<string, string>  $context
     * @param  callable(): T  $work
     * @return T
     */
    public function within(array $context, callable $work): mixed
    {
        $this->stack[] = $context;
        try {
            return $work();
        } finally {
            array_pop($this->stack);
        }
    }

    /** @return array<string, string> */
    public function current(): array
    {
        return array_merge([], ...$this->stack);
    }
}
