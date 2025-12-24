<?php

declare(strict_types=1);

namespace Guardrail\Collector;

/**
 * Filters entry points based on their route paths.
 *
 * Supports glob-like patterns:
 * - '*' matches any single path segment
 * - '**' matches any number of path segments
 *
 * Examples:
 * - '/api/login' matches exactly '/api/login'
 * - '/api/*' matches '/api/users', '/api/orders', etc.
 * - '/api/**' matches '/api/users', '/api/users/123', '/api/admin/users', etc.
 */
final class RoutePathFilterCollector implements CollectorInterface
{
    /**
     * @param list<string> $excludePatterns Route path patterns to exclude
     */
    public function __construct(
        private readonly CollectorInterface $inner,
        private readonly array $excludePatterns,
    ) {}

    public function collect(string $basePath): iterable
    {
        foreach ($this->inner->collect($basePath) as $entryPoint) {
            if (!$this->shouldExclude($entryPoint)) {
                yield $entryPoint;
            }
        }
    }

    private function shouldExclude(EntryPoint $entryPoint): bool
    {
        $routePath = $entryPoint->routePath;
        if ($routePath === null) {
            return false;
        }

        foreach ($this->excludePatterns as $pattern) {
            if ($this->matchesPattern($routePath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $routePath, string $pattern): bool
    {
        // Normalize paths
        $routePath = '/' . ltrim($routePath, '/');
        $pattern = '/' . ltrim($pattern, '/');

        // Exact match
        if ($routePath === $pattern) {
            return true;
        }

        // Convert pattern to regex
        $regex = $this->patternToRegex($pattern);
        return preg_match($regex, $routePath) === 1;
    }

    private function patternToRegex(string $pattern): string
    {
        // Split pattern by ** and * to handle them separately
        // Strategy: escape everything, then replace escaped wildcards with regex
        $escaped = preg_quote($pattern, '#');

        // \*\* (escaped **) -> .* (match any characters including /)
        $escaped = str_replace('\\*\\*', '.*', $escaped);

        // \* (escaped *) -> [^/]* (match any characters except /)
        $escaped = str_replace('\\*', '[^/]*', $escaped);

        return '#^' . $escaped . '$#';
    }
}
