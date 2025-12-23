<?php

declare(strict_types=1);

namespace Guardrail\Analysis\CallGraph;

/**
 * Manages type information for properties and variables.
 */
final class TypeRegistry
{
    /** @var array<string, string> Property key => class type mapping */
    private array $propertyTypes = [];

    public function __construct(
        private readonly ClassHierarchy $classHierarchy,
    ) {}

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
        if ($this->classHierarchy->isTrait($className)) {
            foreach ($this->classHierarchy->findClassesUsingTrait($className) as $class) {
                $type = $this->resolvePropertyType($class, $propertyName);
                if ($type !== null) {
                    return $type;
                }
            }
        }

        // Check parent class
        $parent = $this->classHierarchy->getClassParent($className);
        if ($parent !== null) {
            return $this->resolvePropertyType($parent, $propertyName);
        }

        return null;
    }
}
