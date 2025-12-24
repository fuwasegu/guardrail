<?php

declare(strict_types=1);

namespace Guardrail\Tests\Collector;

use Guardrail\Collector\CollectorInterface;
use Guardrail\Collector\EntryPoint;
use Guardrail\Collector\RouteMethodFilterCollector;
use PHPUnit\Framework\TestCase;

final class RouteMethodFilterCollectorTest extends TestCase
{
    public function testFiltersToAllowedMethods(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'index', 'file.php', routePath: '/users', httpMethod: 'GET'),
            new EntryPoint('Controller', 'store', 'file.php', routePath: '/users', httpMethod: 'POST'),
            new EntryPoint('Controller', 'update', 'file.php', routePath: '/users/{id}', httpMethod: 'PUT'),
            new EntryPoint('Controller', 'destroy', 'file.php', routePath: '/users/{id}', httpMethod: 'DELETE'),
        ]);

        $filter = new RouteMethodFilterCollector($inner, ['POST', 'PUT', 'DELETE']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(3, $entryPoints);

        $methods = array_map(static fn(EntryPoint $ep) => $ep->methodName, $entryPoints);
        $this->assertContains('store', $methods);
        $this->assertContains('update', $methods);
        $this->assertContains('destroy', $methods);
        $this->assertNotContains('index', $methods);
    }

    public function testFiltersToSingleMethod(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'index', 'file.php', routePath: '/users', httpMethod: 'GET'),
            new EntryPoint('Controller', 'store', 'file.php', routePath: '/users', httpMethod: 'POST'),
            new EntryPoint('Controller', 'update', 'file.php', routePath: '/users/{id}', httpMethod: 'PUT'),
        ]);

        $filter = new RouteMethodFilterCollector($inner, ['GET']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(1, $entryPoints);
        $this->assertEquals('index', $entryPoints[0]->methodName);
    }

    public function testCaseInsensitiveMatching(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'index', 'file.php', routePath: '/users', httpMethod: 'GET'),
            new EntryPoint('Controller', 'store', 'file.php', routePath: '/users', httpMethod: 'POST'),
        ]);

        // Lowercase input should still match uppercase HTTP methods
        $filter = new RouteMethodFilterCollector($inner, ['get']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(1, $entryPoints);
        $this->assertEquals('index', $entryPoints[0]->methodName);
    }

    public function testAllowsEntryPointsWithoutHttpMethod(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'index', 'file.php', routePath: '/users', httpMethod: 'GET'),
            new EntryPoint('Controller', 'handle', 'file.php'), // No HTTP method (e.g., from namespace collector)
            new EntryPoint('Controller', 'any', 'file.php', routePath: '/webhook', httpMethod: null), // Route::any()
        ]);

        $filter = new RouteMethodFilterCollector($inner, ['POST']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        // Should include the two entry points without HTTP method
        $this->assertCount(2, $entryPoints);

        $methods = array_map(static fn(EntryPoint $ep) => $ep->methodName, $entryPoints);
        $this->assertContains('handle', $methods);
        $this->assertContains('any', $methods);
    }

    public function testEmptyAllowedMethodsExcludesAll(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'index', 'file.php', routePath: '/users', httpMethod: 'GET'),
            new EntryPoint('Controller', 'store', 'file.php', routePath: '/users', httpMethod: 'POST'),
            new EntryPoint('Controller', 'handle', 'file.php'), // No HTTP method
        ]);

        $filter = new RouteMethodFilterCollector($inner, []);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        // Only entry points without HTTP method should pass through
        $this->assertCount(1, $entryPoints);
        $this->assertEquals('handle', $entryPoints[0]->methodName);
    }

    public function testMultipleAllowedMethods(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'index', 'file.php', routePath: '/users', httpMethod: 'GET'),
            new EntryPoint('Controller', 'store', 'file.php', routePath: '/users', httpMethod: 'POST'),
            new EntryPoint('Controller', 'update', 'file.php', routePath: '/users/{id}', httpMethod: 'PUT'),
            new EntryPoint('Controller', 'patch', 'file.php', routePath: '/users/{id}', httpMethod: 'PATCH'),
            new EntryPoint('Controller', 'destroy', 'file.php', routePath: '/users/{id}', httpMethod: 'DELETE'),
            new EntryPoint('Controller', 'options', 'file.php', routePath: '/users', httpMethod: 'OPTIONS'),
        ]);

        // Only allow "write" methods
        $filter = new RouteMethodFilterCollector($inner, ['POST', 'PUT', 'PATCH', 'DELETE']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(4, $entryPoints);

        $methods = array_map(static fn(EntryPoint $ep) => $ep->methodName, $entryPoints);
        $this->assertNotContains('index', $methods);
        $this->assertNotContains('options', $methods);
    }

    /**
     * @param list<EntryPoint> $entryPoints
     */
    private function createMockCollector(array $entryPoints): CollectorInterface
    {
        return new class($entryPoints) implements CollectorInterface {
            public function __construct(
                private readonly array $entryPoints,
            ) {}

            public function collect(string $basePath): iterable
            {
                return $this->entryPoints;
            }
        };
    }
}
