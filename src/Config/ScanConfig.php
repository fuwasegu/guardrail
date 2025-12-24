<?php

declare(strict_types=1);

namespace Guardrail\Config;

/**
 * Configuration for file scanning in call graph building.
 */
final class ScanConfig
{
    /**
     * @param list<string> $paths Paths to scan (relative to base path)
     * @param list<string> $excludes Patterns to exclude (supports glob patterns)
     */
    public function __construct(
        public readonly array $paths = ['src', 'app'],
        public readonly array $excludes = ['vendor', 'tests'],
    ) {}

    /**
     * Default configuration - scan src/app, exclude vendor/tests.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Scan everything (legacy behavior).
     */
    public static function all(): self
    {
        return new self(paths: ['.'], excludes: []);
    }
}
