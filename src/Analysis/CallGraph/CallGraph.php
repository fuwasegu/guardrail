<?php

declare(strict_types=1);

namespace Guardrail\Analysis\CallGraph;

/**
 * Represents the call graph of a codebase.
 */
final class CallGraph
{
    /** @var array<string, list<MethodCall>> Calls from each method */
    private array $outgoingCalls = [];

    /** @var array<string, list<MethodCall>> Calls to each method */
    private array $incomingCalls = [];

    /** @var array<string, string> Property name => class type mapping */
    private array $propertyTypes = [];

    /** @var array<string, string> Variable name in method => class type */
    private array $variableTypes = [];

    /** @var array<string, string|null> Class => parent class */
    private array $classParents = [];

    /** @var array<string, list<string>> Class => used traits */
    private array $classTraits = [];

    /** @var array<string, string> Method => defining class (where method is defined) */
    private array $methodDefinitions = [];

    /** @var array<string, true> Traits (for distinguishing from classes) */
    private array $traits = [];

    public function addCall(MethodCall $call): void
    {
        $callerId = $call->getCallerIdentifier();
        $this->outgoingCalls[$callerId] ??= [];
        $this->outgoingCalls[$callerId][] = $call;

        if ($call->calleeClass !== null) {
            $calleeId = $call->getCalleeIdentifier();
            $this->incomingCalls[$calleeId] ??= [];
            $this->incomingCalls[$calleeId][] = $call;
        }
    }

    public function addPropertyType(string $className, string $propertyName, string $type): void
    {
        $key = $className . '::$' . $propertyName;
        $this->propertyTypes[$key] = $type;
    }

    public function getPropertyType(string $className, string $propertyName): ?string
    {
        $key = $className . '::$' . $propertyName;
        return $this->propertyTypes[$key] ?? null;
    }

    public function markAsTrait(string $traitName): void
    {
        $this->traits[$traitName] = true;
    }

    public function isTrait(string $className): bool
    {
        return isset($this->traits[$className]);
    }

    /**
     * Resolve property type for a class or trait.
     * For traits, this also searches in classes that use the trait.
     */
    public function resolvePropertyType(string $className, string $propertyName): ?string
    {
        // First check the class/trait's own property
        $type = $this->getPropertyType($className, $propertyName);
        if ($type !== null) {
            return $type;
        }

        // If it's a trait and property not found, search classes that use this trait
        if ($this->isTrait($className)) {
            foreach ($this->classTraits as $class => $traits) {
                if (in_array($className, $traits, true)) {
                    $type = $this->resolvePropertyType($class, $propertyName);
                    if ($type !== null) {
                        return $type;
                    }
                }
            }
        }

        // Check parent class
        $parent = $this->classParents[$className] ?? null;
        if ($parent !== null) {
            return $this->resolvePropertyType($parent, $propertyName);
        }

        return null;
    }

    public function setClassParent(string $className, ?string $parentClass): void
    {
        $this->classParents[$className] = $parentClass;
    }

    /**
     * @param list<string> $traits
     */
    public function setClassTraits(string $className, array $traits): void
    {
        $this->classTraits[$className] = $traits;
    }

    public function addMethodDefinition(string $className, string $methodName): void
    {
        $key = $className . '::' . $methodName;
        $this->methodDefinitions[$key] = $className;
    }

    /**
     * Find where a method is defined for a given class (checking parent classes and traits).
     */
    public function resolveMethodClass(string $className, string $methodName): ?string
    {
        // Check if method is defined in the class itself
        $key = $className . '::' . $methodName;
        if (isset($this->methodDefinitions[$key])) {
            return $className;
        }

        // Check traits
        $traits = $this->classTraits[$className] ?? [];
        foreach ($traits as $trait) {
            $traitKey = $trait . '::' . $methodName;
            if (isset($this->methodDefinitions[$traitKey])) {
                return $trait;
            }
        }

        // Check parent class
        $parent = $this->classParents[$className] ?? null;
        if ($parent !== null) {
            return $this->resolveMethodClass($parent, $methodName);
        }

        return null;
    }

    /**
     * @return list<MethodCall>
     */
    public function getCallsFrom(string $className, string $methodName): array
    {
        $id = $className . '::' . $methodName;
        return $this->outgoingCalls[$id] ?? [];
    }

    /**
     * @return list<MethodCall>
     */
    public function getCallsTo(string $className, string $methodName): array
    {
        $id = $className . '::' . $methodName;
        return $this->incomingCalls[$id] ?? [];
    }

    /**
     * Check if a method (directly or indirectly) calls the target method.
     *
     * @param list<string> $visited Used to prevent infinite recursion
     */
    public function hasPathTo(
        string $fromClass,
        string $fromMethod,
        string $toClass,
        string $toMethod,
        array $visited = [],
    ): bool {
        $fromId = $fromClass . '::' . $fromMethod;

        if (in_array($fromId, $visited, true)) {
            return false;
        }

        $visited[] = $fromId;
        $calls = $this->getCallsFrom($fromClass, $fromMethod);

        foreach ($calls as $call) {
            // Direct match
            if ($call->calleeClass === $toClass && $call->calleeMethod === $toMethod) {
                return true;
            }

            // Recursive search
            if ($call->calleeClass !== null) {
                if ($this->hasPathTo($call->calleeClass, $call->calleeMethod, $toClass, $toMethod, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find the path from one method to another.
     *
     * @param list<string> $visited
     * @return list<MethodCall>|null
     */
    public function findPathTo(
        string $fromClass,
        string $fromMethod,
        string $toClass,
        string $toMethod,
        array $visited = [],
    ): ?array {
        $fromId = $fromClass . '::' . $fromMethod;

        if (in_array($fromId, $visited, true)) {
            return null;
        }

        $visited[] = $fromId;
        $calls = $this->getCallsFrom($fromClass, $fromMethod);

        foreach ($calls as $call) {
            // Direct match
            if ($call->calleeClass === $toClass && $call->calleeMethod === $toMethod) {
                return [$call];
            }

            // Recursive search
            if ($call->calleeClass !== null) {
                $path = $this->findPathTo($call->calleeClass, $call->calleeMethod, $toClass, $toMethod, $visited);
                if ($path !== null) {
                    return [$call, ...$path];
                }
            }
        }

        return null;
    }

    /**
     * Get all methods in the call graph.
     *
     * @return list<string>
     */
    public function getAllMethods(): array
    {
        return array_values(array_unique([
            ...array_keys($this->outgoingCalls),
            ...array_keys($this->incomingCalls),
        ]));
    }
}
