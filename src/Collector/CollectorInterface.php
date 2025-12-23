<?php

declare(strict_types=1);

namespace Guardrail\Collector;

/**
 * Interface for collecting entry points to analyze.
 */
interface CollectorInterface
{
    /**
     * Collect entry points based on the collector's configuration.
     *
     * @param string $basePath Base path of the project
     * @return iterable<EntryPoint>
     */
    public function collect(string $basePath): iterable;
}
