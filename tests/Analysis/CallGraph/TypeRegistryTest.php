<?php

declare(strict_types=1);

namespace Guardrail\Tests\Analysis\CallGraph;

use Guardrail\Analysis\CallGraph\ClassHierarchy;
use Guardrail\Analysis\CallGraph\TypeRegistry;
use PHPUnit\Framework\TestCase;

final class TypeRegistryTest extends TestCase
{
    private ClassHierarchy $hierarchy;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->hierarchy = new ClassHierarchy();
        $this->registry = new TypeRegistry($this->hierarchy);
    }

    public function testAddAndGetPropertyType(): void
    {
        $this->registry->addPropertyType('App\\MyClass', 'service', 'App\\Service');

        $this->assertSame('App\\Service', $this->registry->getPropertyType('App\\MyClass', 'service'));
        $this->assertNull($this->registry->getPropertyType('App\\MyClass', 'unknown'));
        $this->assertNull($this->registry->getPropertyType('App\\Unknown', 'service'));
    }

    public function testResolvePropertyTypeFromSameClass(): void
    {
        $this->registry->addPropertyType('App\\MyClass', 'service', 'App\\Service');

        $this->assertSame('App\\Service', $this->registry->resolvePropertyType('App\\MyClass', 'service'));
    }

    public function testResolvePropertyTypeFromParentClass(): void
    {
        $this->registry->addPropertyType('App\\Parent', 'service', 'App\\Service');
        $this->hierarchy->setClassParent('App\\Child', 'App\\Parent');

        $this->assertSame('App\\Service', $this->registry->resolvePropertyType('App\\Child', 'service'));
    }

    public function testResolvePropertyTypeFromGrandparentClass(): void
    {
        $this->registry->addPropertyType('App\\GrandParent', 'service', 'App\\Service');
        $this->hierarchy->setClassParent('App\\Parent', 'App\\GrandParent');
        $this->hierarchy->setClassParent('App\\Child', 'App\\Parent');

        $this->assertSame('App\\Service', $this->registry->resolvePropertyType('App\\Child', 'service'));
    }

    public function testResolvePropertyTypeFromTraitViaUsingClass(): void
    {
        // Trait uses a property, but type is defined in the class using the trait
        $this->hierarchy->markAsTrait('App\\MyTrait');
        $this->hierarchy->setClassTraits('App\\MyClass', ['App\\MyTrait']);
        $this->registry->addPropertyType('App\\MyClass', 'service', 'App\\Service');

        // When resolving from trait context, should find type in using class
        $this->assertSame('App\\Service', $this->registry->resolvePropertyType('App\\MyTrait', 'service'));
    }

    public function testResolvePropertyTypePrioritizesOwnProperty(): void
    {
        $this->registry->addPropertyType('App\\Child', 'service', 'App\\ChildService');
        $this->registry->addPropertyType('App\\Parent', 'service', 'App\\ParentService');
        $this->hierarchy->setClassParent('App\\Child', 'App\\Parent');

        $this->assertSame('App\\ChildService', $this->registry->resolvePropertyType('App\\Child', 'service'));
    }

    public function testResolvePropertyTypeReturnsNullForUnknown(): void
    {
        $this->assertNull($this->registry->resolvePropertyType('App\\MyClass', 'unknownProperty'));
    }

    public function testResolvePropertyTypeWithMultipleClassesUsingTrait(): void
    {
        $this->hierarchy->markAsTrait('App\\SharedTrait');
        $this->hierarchy->setClassTraits('App\\ClassA', ['App\\SharedTrait']);
        $this->hierarchy->setClassTraits('App\\ClassB', ['App\\SharedTrait']);
        $this->registry->addPropertyType('App\\ClassA', 'service', 'App\\ServiceA');

        // Should find property from ClassA which uses the trait
        $this->assertSame('App\\ServiceA', $this->registry->resolvePropertyType('App\\SharedTrait', 'service'));
    }
}
