<?php

declare(strict_types=1);

namespace Guardrail\Tests\Collector;

use Guardrail\Collector\EntryPoint;
use Guardrail\Collector\RoutePathFilterCollector;
use Guardrail\Collector\CollectorInterface;
use PHPUnit\Framework\TestCase;

final class RoutePathFilterCollectorTest extends TestCase
{
    public function testExcludesExactMatch(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'login', 'file.php', routePath: '/api/login'),
            new EntryPoint('Controller', 'users', 'file.php', routePath: '/api/users'),
        ]);

        $filter = new RoutePathFilterCollector($inner, ['/api/login']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(1, $entryPoints);
        $this->assertEquals('users', $entryPoints[0]->methodName);
    }

    public function testExcludesWithSingleWildcard(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'login', 'file.php', routePath: '/api/login'),
            new EntryPoint('Controller', 'users', 'file.php', routePath: '/api/users'),
            new EntryPoint('Controller', 'orders', 'file.php', routePath: '/api/orders'),
            new EntryPoint('Controller', 'nested', 'file.php', routePath: '/api/users/nested'),
        ]);

        // '/api/*' should match '/api/login', '/api/users', '/api/orders' but NOT '/api/users/nested'
        $filter = new RoutePathFilterCollector($inner, ['/api/*']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(1, $entryPoints);
        $this->assertEquals('nested', $entryPoints[0]->methodName);
    }

    public function testExcludesWithDoubleWildcard(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'login', 'file.php', routePath: '/api/login'),
            new EntryPoint('Controller', 'users', 'file.php', routePath: '/api/users'),
            new EntryPoint('Controller', 'nested', 'file.php', routePath: '/api/users/123'),
            new EntryPoint('Controller', 'deepNested', 'file.php', routePath: '/api/admin/users/list'),
            new EntryPoint('Controller', 'health', 'file.php', routePath: '/health'),
        ]);

        // '/api/**' should match all '/api/*' paths
        $filter = new RoutePathFilterCollector($inner, ['/api/**']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(1, $entryPoints);
        $this->assertEquals('health', $entryPoints[0]->methodName);
    }

    public function testExcludesMultiplePatterns(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'login', 'file.php', routePath: '/api/login'),
            new EntryPoint('Controller', 'logout', 'file.php', routePath: '/api/logout'),
            new EntryPoint('Controller', 'users', 'file.php', routePath: '/api/users'),
            new EntryPoint('Controller', 'health', 'file.php', routePath: '/health'),
        ]);

        $filter = new RoutePathFilterCollector($inner, ['/api/login', '/api/logout', '/health']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(1, $entryPoints);
        $this->assertEquals('users', $entryPoints[0]->methodName);
    }

    public function testDoesNotExcludeEntriesWithoutRoutePath(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'login', 'file.php', routePath: '/api/login'),
            new EntryPoint('Controller', 'handle', 'file.php'), // No route path (e.g., from namespace collector)
        ]);

        $filter = new RoutePathFilterCollector($inner, ['/api/**']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(1, $entryPoints);
        $this->assertEquals('handle', $entryPoints[0]->methodName);
    }

    public function testNormalizesPathsWithoutLeadingSlash(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'login', 'file.php', routePath: 'api/login'),
            new EntryPoint('Controller', 'users', 'file.php', routePath: '/api/users'),
        ]);

        // Pattern and path should both be normalized
        $filter = new RoutePathFilterCollector($inner, ['api/login']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(1, $entryPoints);
        $this->assertEquals('users', $entryPoints[0]->methodName);
    }

    public function testPartialSegmentDoesNotMatch(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'users', 'file.php', routePath: '/api/users'),
            new EntryPoint('Controller', 'users2', 'file.php', routePath: '/api/users2'),
        ]);

        // '/api/user' should NOT match '/api/users' or '/api/users2'
        $filter = new RoutePathFilterCollector($inner, ['/api/user']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(2, $entryPoints);
    }

    public function testWildcardMatchesFullSegment(): void
    {
        $inner = $this->createMockCollector([
            new EntryPoint('Controller', 'users', 'file.php', routePath: '/api/users'),
            new EntryPoint('Controller', 'users123', 'file.php', routePath: '/api/users123'),
        ]);

        // '/api/*' should match both
        $filter = new RoutePathFilterCollector($inner, ['/api/*']);
        $entryPoints = iterator_to_array($filter->collect(''), preserve_keys: false);

        $this->assertCount(0, $entryPoints);
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
