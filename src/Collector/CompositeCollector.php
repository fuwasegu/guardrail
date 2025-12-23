<?php

declare(strict_types=1);

namespace Guardrail\Collector;

/**
 * Combines multiple collectors with AND/OR logic.
 */
final class CompositeCollector implements CollectorInterface
{
    /**
     * @param list<CollectorInterface> $collectors
     */
    public function __construct(
        private readonly array $collectors,
        private readonly bool $isOr = false,
    ) {}

    public static function or(CollectorInterface ...$collectors): self
    {
        return new self(array_values($collectors), isOr: true);
    }

    public static function and(CollectorInterface ...$collectors): self
    {
        return new self(array_values($collectors), isOr: false);
    }

    public function collect(string $basePath): iterable
    {
        if ($this->isOr) {
            // Union: yield from all collectors, deduplicate by identifier
            $seen = [];
            foreach ($this->collectors as $collector) {
                foreach ($collector->collect($basePath) as $entryPoint) {
                    $id = $entryPoint->getIdentifier();
                    if (!isset($seen[$id])) {
                        $seen[$id] = true;
                        yield $entryPoint;
                    }
                }
            }
        } else {
            // Intersection: only yield entry points present in ALL collectors
            if ($this->collectors === []) {
                return;
            }

            // Collect all entry points from each collector once and cache by identifier
            /** @var list<array<string, EntryPoint>> $collectorResults */
            $collectorResults = [];
            foreach ($this->collectors as $collector) {
                $results = [];
                foreach ($collector->collect($basePath) as $entryPoint) {
                    $results[$entryPoint->getIdentifier()] = $entryPoint;
                }
                $collectorResults[] = $results;
            }

            // Find intersection: entry points present in all collectors
            $first = $collectorResults[0];
            foreach ($first as $id => $entryPoint) {
                $inAll = true;
                for ($i = 1; $i < count($collectorResults); $i++) {
                    if (!isset($collectorResults[$i][$id])) {
                        $inAll = false;
                        break;
                    }
                }

                if ($inAll) {
                    yield $entryPoint;
                }
            }
        }
    }
}
