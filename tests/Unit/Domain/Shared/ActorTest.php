<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use PHPUnit\Framework\TestCase;

final class ActorTest extends TestCase
{
    public function test_operator_actor(): void
    {
        $a = Actor::operator('0192F0A0-1234-7abc-8def-0123456789ab', 'Owner 1');

        $this->assertSame('0192f0a0-1234-7abc-8def-0123456789ab', $a->userId);
        $this->assertFalse($a->isSystem());
    }

    public function test_system_actor(): void
    {
        $this->assertTrue(Actor::system()->isSystem());
        $this->assertNull(Actor::system('console')->userId);
    }

    public function test_operator_id_must_be_uuid(): void
    {
        $this->expectException(InvariantViolation::class);
        Actor::operator('42', 'x');
    }
}
