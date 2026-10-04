<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Enforces the dependency rule of the layered architecture by scanning
 * `use` statements and fully-qualified references in source files:
 *
 *   Domain        → may depend on nothing but itself (+ the math library)
 *   Application   → may depend on Domain only
 *   Infrastructure→ may depend on Application, Domain, Laravel
 *   Http          → may depend on Application, Domain, Laravel (thin adapters)
 */
final class LayerDependencyTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function rules(): iterable
    {
        yield 'Domain is framework-free' => ['app/Domain', ['App\\Application', 'App\\Infrastructure', 'App\\Http', 'Illuminate\\', 'Laravel\\']];
        yield 'Application does not know adapters or Laravel' => ['app/Application', ['App\\Infrastructure', 'App\\Http', 'Illuminate\\', 'Laravel\\']];
        yield 'Infrastructure does not depend on HTTP' => ['app/Infrastructure', ['App\\Http']];
        // Bounded contexts talk through ids, not through each other's classes.
        yield 'Catalog domain is independent' => ['app/Domain/Catalog', ['App\\Domain\\Partner', 'App\\Domain\\Network']];
        yield 'Partner domain is independent' => ['app/Domain/Partner', ['App\\Domain\\Catalog', 'App\\Domain\\Network']];
        yield 'Network domain is independent' => ['app/Domain/Network', ['App\\Domain\\Catalog', 'App\\Domain\\Partner']];
        yield 'Procurement domain is independent' => ['app/Domain/Procurement', ['App\\Domain\\Inventory', 'App\\Domain\\Catalog', 'App\\Domain\\Partner', 'App\\Domain\\Network']];
        yield 'Inventory domain is independent' => ['app/Domain/Inventory', ['App\\Domain\\Procurement', 'App\\Domain\\Catalog', 'App\\Domain\\Partner', 'App\\Domain\\Network']];
        // Procurement (application) may post to Inventory; never the reverse → no cycle.
        yield 'Sales domain is independent' => ['app/Domain/Sales', ['App\\Domain\\Inventory', 'App\\Domain\\Procurement', 'App\\Domain\\Catalog', 'App\\Domain\\Partner', 'App\\Domain\\Network']];
        yield 'Procurement domain does not know Sales' => ['app/Domain/Procurement', ['App\\Domain\\Sales']];
        yield 'Inventory domain does not know Sales' => ['app/Domain/Inventory', ['App\\Domain\\Sales']];
        // Sales (application) may post to Inventory; never the reverse.
        yield 'Inventory application does not know sales' => ['app/Application/Inventory', ['App\\Domain\\Sales', 'App\\Application\\Sales']];
        yield 'Procurement application does not know sales' => ['app/Application/Procurement', ['App\\Domain\\Sales', 'App\\Application\\Sales']];
        yield 'Inventory application does not know procurement' => ['app/Application/Inventory', ['App\\Domain\\Procurement', 'App\\Application\\Procurement']];
        yield 'Master data does not know transactions' => ['app/Domain/Catalog', ['App\\Domain\\Procurement', 'App\\Domain\\Inventory']];
        yield 'Shared kernel depends on no context' => ['app/Domain/Shared', ['App\\Domain\\Catalog', 'App\\Domain\\Partner', 'App\\Domain\\Network', 'App\\Domain\\Identity', 'App\\Domain\\Procurement', 'App\\Domain\\Inventory', 'App\\Domain\\Sales']];
        yield 'Shared application services know no transaction context' => ['app/Application/Shared', ['App\\Domain\\Procurement', 'App\\Domain\\Inventory', 'App\\Application\\Procurement', 'App\\Application\\Inventory', 'App\\Domain\\Sales', 'App\\Application\\Sales']];
    }

    /** @param list<string> $forbidden */
    #[DataProvider('rules')]
    public function test_layer_does_not_depend_on_forbidden_namespaces(string $dir, array $forbidden): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];
        $files = 0;

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}"));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files++;
            $code = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $ns) {
                // `use X\…` imports and fully-qualified `\X\…` references
                if (preg_match('/^use\s+'.preg_quote($ns, '/').'/m', $code) === 1 || str_contains($code, '\\'.$ns)) {
                    $violations[] = str_replace($root.'/', '', $file->getPathname())." → {$ns}";
                }
            }
        }

        $this->assertGreaterThan(0, $files, "No PHP files found in {$dir}");
        $this->assertSame([], $violations, "Layer rule violated:\n".implode("\n", $violations));
    }

    public function test_controllers_do_not_touch_the_database_directly(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app/Http/Controllers"));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'HealthController.php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // `$request->query(...)` (HTTP query string) is fine; database access is not.
            foreach (['Illuminate\\Support\\Facades\\DB', 'Illuminate\\Database', 'App\\Infrastructure', '::query(', '->table(', 'DB::'] as $needle) {
                if (str_contains($code, $needle)) {
                    $violations[] = $file->getFilename()." uses {$needle}";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
