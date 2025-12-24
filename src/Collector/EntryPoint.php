<?php

declare(strict_types=1);

namespace Guardrail\Collector;

/**
 * Represents an entry point for analysis.
 */
final class EntryPoint
{
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
        public readonly string $filePath,
        public readonly ?string $description = null,
        public readonly ?string $routePath = null,
        public readonly ?string $httpMethod = null,
    ) {}

    public function getIdentifier(): string
    {
        return $this->className . '::' . $this->methodName;
    }

    public function __toString(): string
    {
        return $this->description ?? $this->getIdentifier();
    }
}
