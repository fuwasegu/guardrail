<?php

declare(strict_types=1);

namespace Guardrail\Analysis\CallGraph;

/**
 * Manages class inheritance, traits, and method definitions.
 */
final class ClassHierarchy
{
    /** @var array<string, string|null> Class => parent class */
    private array $classParents = [];

    /** @var array<string, list<string>> Class => used traits */
    private array $classTraits = [];

    /** @var array<string, string> Method => defining class (where method is defined) */
    private array $methodDefinitions = [];

    /** @var array<string, true> Traits (for distinguishing from classes) */
    private array $traits = [];

    public function setClassParent(string $className, ?string $parentClass): void
    {
        $this->classParents[$className] = $parentClass;
    }

    public function getClassParent(string $className): ?string
    {
        return $this->classParents[$className] ?? null;
    }

    /**
     * @param list<string> $traits
     */
    public function setClassTraits(string $className, array $traits): void
    {
        $this->classTraits[$className] = $traits;
    }

    /**
     * @return list<string>
     */
    public function getClassTraits(string $className): array
    {
        return $this->classTraits[$className] ?? [];
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
     * Find classes that use the given trait.
     *
     * @return list<string>
     */
    public function findClassesUsingTrait(string $traitName): array
    {
        $classes = [];
        foreach ($this->classTraits as $class => $traits) {
            if (!in_array($traitName, $traits, strict: true)) {
                continue;
            }

            $classes[] = $class;
        }
        return $classes;
    }

    public function addMethodDefinition(string $className, string $methodName): void
    {
        $key = $className . '::' . $methodName;
        $this->methodDefinitions[$key] = $className;
    }

    public function hasMethodDefinition(string $className, string $methodName): bool
    {
        $key = $className . '::' . $methodName;
        return isset($this->methodDefinitions[$key]);
    }

    /**
     * Find where a method is defined for a given class (checking parent classes and traits).
     */
    public function resolveMethodClass(string $className, string $methodName): ?string
    {
        // Check if method is defined in the class itself
        if ($this->hasMethodDefinition($className, $methodName)) {
            return $className;
        }

        // Check traits
        foreach ($this->getClassTraits($className) as $trait) {
            if (!$this->hasMethodDefinition($trait, $methodName)) {
                continue;
            }

            return $trait;
        }

        // Check parent class
        $parent = $this->getClassParent($className);
        if ($parent !== null) {
            return $this->resolveMethodClass($parent, $methodName);
        }

        return null;
    }
}
