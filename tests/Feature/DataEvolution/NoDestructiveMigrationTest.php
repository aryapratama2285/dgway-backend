<?php

declare(strict_types=1);

namespace Tests\Feature\DataEvolution;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

/** A5 addendum §3 — production never runs the destructive migration commands. */
final class NoDestructiveMigrationTest extends TestCase
{
    private const COMMANDS = [FreshCommand::class, RefreshCommand::class, ResetCommand::class, RollbackCommand::class, WipeCommand::class];

    private function prohibited(string $command): bool
    {
        return (bool) (new ReflectionProperty($command, 'prohibitedFromRunning'))->getValue();
    }

    public function test_destructive_commands_are_prohibited_in_production_only(): void
    {
        try {
            $this->app['env'] = 'production';
            (new \App\Providers\AppServiceProvider($this->app))->boot();
            foreach (self::COMMANDS as $c) {
                $this->assertTrue($this->prohibited($c), $c);
            }
        } finally {
            $this->app['env'] = 'testing';
            DB::prohibitDestructiveCommands(false);
            \Illuminate\Database\Eloquent\Model::shouldBeStrict(true);
        }
        foreach (self::COMMANDS as $c) {
            $this->assertFalse($this->prohibited($c), "{$c} stays available outside production (tests, local)");
        }
    }
}
