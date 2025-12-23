<?php

declare(strict_types=1);

namespace Guardrail\Config;

/**
 * Represents a reference to a class method.
 *
 * @example
 * MethodReference::fromArray([Authorizer::class, 'authorize'])
 */
final class MethodReference implements \Stringable
{
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
    ) {}

    /**
     * @param array{0: class-string, 1: string} $array
     */
    public static function fromArray(array $array): self
    {
        return new self($array[0], $array[1]);
    }

    public function matches(string $className, string $methodName): bool
    {
        return $this->matchesClass($className) && $this->matchesMethod($methodName);
    }

    private function matchesClass(string $className): bool
    {
        // Support wildcard patterns like '*Repository'
        if (str_contains($this->className, '*')) {
            $pattern =
                '/^'
                . str_replace(search: '\\*', replace: '.*', subject: preg_quote($this->className, delimiter: '/'))
                . '$/';
            return (bool) preg_match($pattern, $className);
        }

        return $this->className === $className;
    }

    private function matchesMethod(string $methodName): bool
    {
        // Support wildcard '*' for any method
        if ($this->methodName === '*') {
            return true;
        }

        return $this->methodName === $methodName;
    }

    public function __toString(): string
    {
        return $this->className . '::' . $this->methodName;
    }
}
