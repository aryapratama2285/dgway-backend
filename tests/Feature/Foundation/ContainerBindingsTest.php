<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Tests\TestCase;

/**
 * Every port (interface) declared in Domain/Application must resolve to an
 * Infrastructure adapter, so no use case can fail at runtime with an
 * unbound interface.
 */
final class ContainerBindingsTest extends TestCase
{
    public function test_every_domain_and_application_interface_is_bound_to_infrastructure(): void
    {
        $root = dirname(__DIR__, 3);
        $checked = [];

        foreach (['app/Domain', 'app/Application'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}"));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen("{$root}/app/")));
                if (! interface_exists($class)) {
                    continue;
                }
                if ((new \ReflectionClass($class))->getMethods() === []) {
                    continue; // marker interface (e.g. ConflictsWithCurrentState), not a port
                }
                $impl = $this->app->make($class);
                $this->assertInstanceOf($class, $impl);
                // Persistence/IO ports → Infrastructure adapters; pure policies may be domain classes.
                $this->assertMatchesRegularExpression('/^App\\\\(Infrastructure|Domain)\\\\/', $impl::class, $class.' bound to '.$impl::class);
                if (preg_match('/(Repository|Ledger|Queries|Store|Recorder|Projection|Generator|Manager|Directory|Registry|Service|Checker|Context|Gate)$/', $class)) {
                    $this->assertStringStartsWith('App\\Infrastructure\\', $impl::class, "{$class} must be implemented in Infrastructure");
                }
                $checked[] = $class;
            }
        }

        $this->assertGreaterThanOrEqual(15, count($checked), 'expected all ports to be discovered');
    }
}
