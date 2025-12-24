<?php

declare(strict_types=1);

namespace Guardrail\Config;

use Guardrail\Collector\CollectorInterface;
use Guardrail\Collector\CompositeCollector;
use Guardrail\Collector\ExcludingCollector;
use Guardrail\Collector\NamespaceCollector;
use Guardrail\Collector\RouteCollector;
use Guardrail\Collector\RouteMethodFilterCollector;
use Guardrail\Collector\RoutePathFilterCollector;

/**
 * Fluent builder for configuring entry point collectors.
 */
final class EntryPointBuilder
{
    /** @var list<CollectorInterface> */
    private array $collectors = [];

    /** @var list<CollectorInterface> */
    private array $exclusions = [];

    /** @var list<string> */
    private array $excludedRoutePaths = [];

    /** @var list<string> Allowed HTTP methods (empty = all allowed) */
    private array $allowedHttpMethods = [];

    private ?NamespaceCollector $currentNamespaceCollector = null;

    private bool $inExcluding = false;

    public function __construct(
        private readonly RuleBuilder $parent,
    ) {}

    public function namespace(string $pattern): self
    {
        $collector = (new NamespaceCollector())->namespace($pattern);

        if ($this->inExcluding) {
            $this->exclusions[] = $collector;
            return $this;
        }

        $this->collectors[] = $collector;
        $this->currentNamespaceCollector = $collector;

        return $this;
    }

    /**
     * Add entry points from a Laravel route file.
     *
     * @param string $routeFile Path to route file relative to project root (e.g., 'routes/api.php')
     * @param string $prefix Base prefix applied to all routes (e.g., '/api' from RouteServiceProvider)
     */
    public function route(string $routeFile, string $prefix = ''): self
    {
        $collector = (new RouteCollector())->routeFile($routeFile, $prefix);

        if ($this->inExcluding) {
            $this->exclusions[] = $collector;
            return $this;
        }

        $this->collectors[] = $collector;
        $this->currentNamespaceCollector = null;

        return $this;
    }

    public function method(string ...$methods): self
    {
        if ($this->currentNamespaceCollector !== null && !$this->inExcluding) {
            // Replace the last collector with one that has method filter
            array_pop($this->collectors);
            $this->currentNamespaceCollector = $this->currentNamespaceCollector->method(...$methods);
            $this->collectors[] = $this->currentNamespaceCollector;
        }

        return $this;
    }

    public function publicMethods(): self
    {
        if ($this->currentNamespaceCollector !== null && !$this->inExcluding) {
            array_pop($this->collectors);
            $this->currentNamespaceCollector = $this->currentNamespaceCollector->publicMethods();
            $this->collectors[] = $this->currentNamespaceCollector;
        }

        return $this;
    }

    public function excluding(): self
    {
        $this->inExcluding = true;
        $this->currentNamespaceCollector = null;
        return $this;
    }

    /**
     * Exclude specific route paths from analysis.
     *
     * Supports glob-like patterns:
     * - '/api/login' - exact match
     * - '/api/*' - matches single segment (e.g., '/api/users', '/api/orders')
     * - '/api/**' - matches any segments (e.g., '/api/users/123', '/api/admin/users')
     *
     * @param string ...$patterns Route path patterns to exclude
     */
    public function excludeRoutes(string ...$patterns): self
    {
        foreach ($patterns as $pattern) {
            $this->excludedRoutePaths[] = $pattern;
        }
        return $this;
    }

    /**
     * Filter route entry points by HTTP method.
     *
     * Only routes matching the specified HTTP methods will be included.
     * If not called, all HTTP methods are allowed.
     *
     * @param string ...$methods HTTP methods to allow (e.g., 'GET', 'POST', 'PUT', 'DELETE')
     */
    public function httpMethod(string ...$methods): self
    {
        foreach ($methods as $method) {
            $this->allowedHttpMethods[] = strtoupper($method);
        }
        return $this;
    }

    public function or(): self
    {
        $this->inExcluding = false;
        $this->currentNamespaceCollector = null;
        return $this;
    }

    /**
     * End entry point configuration and return to the rule builder.
     */
    public function end(): RuleBuilder
    {
        return $this->parent;
    }

    public function build(): CollectorInterface
    {
        $baseCollector = match (count($this->collectors)) {
            0 => throw new \LogicException('At least one entry point collector must be specified'),
            1 => $this->collectors[0],
            default => CompositeCollector::or(...$this->collectors),
        };

        // Apply namespace/class exclusions
        if ($this->exclusions !== []) {
            $exclusionCollector = match (count($this->exclusions)) {
                1 => $this->exclusions[0],
                default => CompositeCollector::or(...$this->exclusions),
            };
            $baseCollector = new ExcludingCollector($baseCollector, $exclusionCollector);
        }

        // Apply route path exclusions
        if ($this->excludedRoutePaths !== []) {
            $baseCollector = new RoutePathFilterCollector($baseCollector, $this->excludedRoutePaths);
        }

        // Apply HTTP method filter
        if ($this->allowedHttpMethods !== []) {
            $baseCollector = new RouteMethodFilterCollector($baseCollector, $this->allowedHttpMethods);
        }

        return $baseCollector;
    }
}
