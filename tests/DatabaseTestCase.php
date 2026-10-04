<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Shared\Time\Clock;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FrozenClock;

/**
 * Base class for tests that hit the real PostgreSQL test database.
 *
 * Uses DatabaseTruncation (not RefreshDatabase) on purpose: RefreshDatabase
 * wraps each test in a transaction, which would hide exactly what these tests
 * must prove — real COMMIT / ROLLBACK behaviour of the transaction boundary,
 * idempotency and document sequences.
 *
 * Reference data inserted by migrations (uoms) is kept between tests.
 */
abstract class DatabaseTestCase extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected $exceptTables = ['migrations', 'uoms'];

    protected FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock('2026-10-03T01:00:00Z');
        $this->app->instance(Clock::class, $this->clock);
    }
}
