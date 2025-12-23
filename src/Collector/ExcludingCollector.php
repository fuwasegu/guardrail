<?php

declare(strict_types=1);

namespace Guardrail\Collector;

/**
 * Wraps a collector and excludes entry points matching exclusion patterns.
 */
final class ExcludingCollector implements CollectorInterface
{
    public function __construct(
        private readonly CollectorInterface $baseCollector,
        private readonly CollectorInterface $exclusionCollector,
    ) {}

    public function collect(string $basePath): iterable
    {
        // Collect all exclusions first
        $exclusions = [];
        foreach ($this->exclusionCollector->collect($basePath) as $excluded) {
            $exclusions[$excluded->getIdentifier()] = true;
        }

        // Yield from base collector, excluding matches
        foreach ($this->baseCollector->collect($basePath) as $entryPoint) {
            if (isset($exclusions[$entryPoint->getIdentifier()])) {
                continue;
            }

            yield $entryPoint;
        }
    }
}
