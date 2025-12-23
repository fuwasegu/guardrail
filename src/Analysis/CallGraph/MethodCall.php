<?php

declare(strict_types=1);

namespace Guardrail\Analysis\CallGraph;

/**
 * Represents a method call found in the code.
 */
final readonly class MethodCall
{
    public function __construct(
        public ?string $callerClass,
        public string $callerMethod,
        public ?string $calleeClass,
        public string $calleeMethod,
        public int $line,
        public bool $isStatic = false,
        public ?string $variableName = null,
    ) {}

    public function getCalleeIdentifier(): string
    {
        if ($this->calleeClass === null) {
            return $this->calleeMethod;
        }
        return $this->calleeClass . '::' . $this->calleeMethod;
    }

    public function getCallerIdentifier(): string
    {
        if ($this->callerClass === null) {
            return $this->callerMethod;
        }
        return $this->callerClass . '::' . $this->callerMethod;
    }
}
