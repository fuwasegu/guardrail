<?php

declare(strict_types=1);

namespace Guardrail\Collector;

/**
 * Filters entry points based on their HTTP methods.
 *
 * Only allows entry points with HTTP methods in the allowed list.
 * Entry points without an HTTP method (e.g., non-route entry points) are always allowed.
 */
final class RouteMethodFilterCollector implements CollectorInterface
{
    /** @var list<string> Allowed HTTP methods (uppercase) */
    private readonly array $allowedMethods;

    /**
     * @param list<string> $allowedMethods HTTP methods to allow (e.g., 'GET', 'POST')
     */
    public function __construct(
        private readonly CollectorInterface $inner,
        array $allowedMethods,
    ) {
        $this->allowedMethods = array_map('strtoupper', $allowedMethods);
    }

    public function collect(string $basePath): iterable
    {
        foreach ($this->inner->collect($basePath) as $entryPoint) {
            if ($this->shouldInclude($entryPoint)) {
                yield $entryPoint;
            }
        }
    }

    private function shouldInclude(EntryPoint $entryPoint): bool
    {
        $httpMethod = $entryPoint->httpMethod;

        // Non-route entry points (no HTTP method) are always included
        if ($httpMethod === null) {
            return true;
        }

        return in_array($httpMethod, $this->allowedMethods, strict: true);
    }
}
