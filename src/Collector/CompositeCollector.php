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
            yield from $this->collectUnion($basePath);
            return;
        }

        yield from $this->collectIntersection($basePath);
    }

    /**
     * @return iterable<EntryPoint>
     */
    private function collectUnion(string $basePath): iterable
    {
        $seen = [];
        foreach ($this->collectors as $collector) {
            foreach ($collector->collect($basePath) as $entryPoint) {
                $id = $entryPoint->getIdentifier();
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                yield $entryPoint;
            }
        }
    }

    /**
     * @return iterable<EntryPoint>
     */
    private function collectIntersection(string $basePath): iterable
    {
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
            if (!$this->isPresentInAll($id, $collectorResults)) {
                continue;
            }

            yield $entryPoint;
        }
    }

    /**
     * @param list<array<string, EntryPoint>> $collectorResults
     */
    private function isPresentInAll(string $id, array $collectorResults): bool
    {
        for ($i = 1; $i < count($collectorResults); $i++) {
            if (!isset($collectorResults[$i][$id])) {
                return false;
            }
        }

        return true;
    }
}
