<?php

declare(strict_types=1);

namespace Guardrail\Tests\Collector;

use Guardrail\Collector\EntryPoint;
use Guardrail\Collector\RouteCollector;
use PHPUnit\Framework\TestCase;

final class RouteCollectorTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = dirname(__DIR__) . '/Fixtures';
    }

    public function testCollectsBasicRoutes(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/api.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        $this->assertNotEmpty($entryPoints);

        $identifiers = array_map(static fn(EntryPoint $ep) => $ep->getIdentifier(), $entryPoints);

        $this->assertContains('App\\Http\\Controllers\\UserController::index', $identifiers);
        $this->assertContains('App\\Http\\Controllers\\UserController::store', $identifiers);
        $this->assertContains('App\\Http\\Controllers\\UserController::show', $identifiers);
    }

    public function testCollectsGroupedRoutes(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/api.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        $identifiers = array_map(static fn(EntryPoint $ep) => $ep->getIdentifier(), $entryPoints);

        // Routes inside middleware group
        $this->assertContains('App\\Http\\Controllers\\UserController::update', $identifiers);
        $this->assertContains('App\\Http\\Controllers\\UserController::destroy', $identifiers);

        // Routes inside prefix group
        $this->assertContains('App\\Http\\Controllers\\OrderController::index', $identifiers);
        $this->assertContains('App\\Http\\Controllers\\OrderController::store', $identifiers);
    }

    public function testCollectsModuleRoutes(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/api.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        $identifiers = array_map(static fn(EntryPoint $ep) => $ep->getIdentifier(), $entryPoints);

        // Module routes (modular monolith)
        $this->assertContains('App\\Modules\\Billing\\Http\\Controllers\\InvoiceController::index', $identifiers);
        $this->assertContains('App\\Modules\\Billing\\Http\\Controllers\\InvoiceController::show', $identifiers);
        $this->assertContains('App\\Modules\\Billing\\Http\\Controllers\\InvoiceController::create', $identifiers);
    }

    public function testReturnsEmptyForNonExistentFile(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/nonexistent.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        $this->assertEmpty($entryPoints);
    }

    public function testEntryPointsHaveDescription(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/api.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        $firstEntryPoint = $entryPoints[0];

        $this->assertNotNull($firstEntryPoint->description);
        $this->assertStringContainsString('Route:', $firstEntryPoint->description);
    }

    public function testCollectsFromMultipleRouteFiles(): void
    {
        // Create a second route file
        $webRoutesContent = <<<'PHP'
        <?php

        use App\Http\Controllers\HomeController;
        use Illuminate\Support\Facades\Route;

        Route::get('/', [HomeController::class, 'index']);
        PHP;

        $webRoutesPath = $this->fixturesPath . '/routes/web.php';
        file_put_contents($webRoutesPath, $webRoutesContent);

        try {
            $collector = (new RouteCollector())
                ->routeFile('routes/api.php')
                ->routeFile('routes/web.php');

            $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

            $identifiers = array_map(static fn(EntryPoint $ep) => $ep->getIdentifier(), $entryPoints);

            // From api.php
            $this->assertContains('App\\Http\\Controllers\\UserController::index', $identifiers);
            // From web.php
            $this->assertContains('App\\Http\\Controllers\\HomeController::index', $identifiers);
        } finally {
            if (file_exists($webRoutesPath)) {
                unlink($webRoutesPath);
            }
        }
    }

    public function testTotalEntryPointCount(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/api.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        // Expected: 5 UserController + 2 OrderController + 3 InvoiceController = 10
        $this->assertCount(10, $entryPoints);
    }

    public function testExtractsRoutePath(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/api.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        // Find the entry point for UserController::index
        $userIndexEntryPoint = null;
        foreach ($entryPoints as $ep) {
            if ($ep->className === 'App\\Http\\Controllers\\UserController' && $ep->methodName === 'index') {
                $userIndexEntryPoint = $ep;
                break;
            }
        }

        $this->assertNotNull($userIndexEntryPoint);
        $this->assertEquals('/users', $userIndexEntryPoint->routePath);
        $this->assertStringContainsString('/users', $userIndexEntryPoint->description);
    }

    public function testEntryPointsHaveRoutePath(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/api.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        // All entry points from route files should have route paths
        foreach ($entryPoints as $ep) {
            $this->assertNotNull($ep->routePath, "Entry point {$ep->getIdentifier()} should have a route path");
        }
    }

    public function testExtractsPrefixedRoutePath(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/api.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        // Find the entry point for OrderController::index (inside Route::prefix('orders'))
        $orderIndexEntryPoint = null;
        foreach ($entryPoints as $ep) {
            if ($ep->className === 'App\\Http\\Controllers\\OrderController' && $ep->methodName === 'index') {
                $orderIndexEntryPoint = $ep;
                break;
            }
        }

        $this->assertNotNull($orderIndexEntryPoint);
        $this->assertEquals('/orders', $orderIndexEntryPoint->routePath);
    }

    public function testExtractsNestedPrefixedRoutePath(): void
    {
        $collector = (new RouteCollector())->routeFile('routes/api.php');

        $entryPoints = iterator_to_array($collector->collect($this->fixturesPath), preserve_keys: false);

        // Find the entry point for InvoiceController::index (inside Route::prefix('billing'))
        $invoiceIndexEntryPoint = null;
        foreach ($entryPoints as $ep) {
            if ($ep->className === 'App\\Modules\\Billing\\Http\\Controllers\\InvoiceController' && $ep->methodName === 'index') {
                $invoiceIndexEntryPoint = $ep;
                break;
            }
        }

        $this->assertNotNull($invoiceIndexEntryPoint);
        $this->assertEquals('/billing/invoices', $invoiceIndexEntryPoint->routePath);
    }
}
