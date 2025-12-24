<?php

declare(strict_types=1);

namespace Guardrail\Tests\Analysis\CallGraph;

use Guardrail\Analysis\CallGraph\ClassHierarchy;
use PHPUnit\Framework\TestCase;

final class ClassHierarchyTest extends TestCase
{
    private ClassHierarchy $hierarchy;

    protected function setUp(): void
    {
        $this->hierarchy = new ClassHierarchy();
    }

    public function testSetAndGetClassParent(): void
    {
        $this->hierarchy->setClassParent('App\\Child', 'App\\Parent');

        $this->assertSame('App\\Parent', $this->hierarchy->getClassParent('App\\Child'));
        $this->assertNull($this->hierarchy->getClassParent('App\\Unknown'));
    }

    public function testSetAndGetClassTraits(): void
    {
        $this->hierarchy->setClassTraits('App\\MyClass', ['App\\TraitA', 'App\\TraitB']);

        $this->assertSame(['App\\TraitA', 'App\\TraitB'], $this->hierarchy->getClassTraits('App\\MyClass'));
        $this->assertSame([], $this->hierarchy->getClassTraits('App\\Unknown'));
    }

    public function testMarkAndCheckTrait(): void
    {
        $this->hierarchy->markAsTrait('App\\MyTrait');

        $this->assertTrue($this->hierarchy->isTrait('App\\MyTrait'));
        $this->assertFalse($this->hierarchy->isTrait('App\\MyClass'));
    }

    public function testFindClassesUsingTrait(): void
    {
        $this->hierarchy->setClassTraits('App\\ClassA', ['App\\TraitX']);
        $this->hierarchy->setClassTraits('App\\ClassB', ['App\\TraitX', 'App\\TraitY']);
        $this->hierarchy->setClassTraits('App\\ClassC', ['App\\TraitY']);

        $classes = $this->hierarchy->findClassesUsingTrait('App\\TraitX');

        $this->assertCount(2, $classes);
        $this->assertContains('App\\ClassA', $classes);
        $this->assertContains('App\\ClassB', $classes);
    }

    public function testAddAndHasMethodDefinition(): void
    {
        $this->hierarchy->addMethodDefinition('App\\MyClass', 'execute');

        $this->assertTrue($this->hierarchy->hasMethodDefinition('App\\MyClass', 'execute'));
        $this->assertFalse($this->hierarchy->hasMethodDefinition('App\\MyClass', 'unknown'));
        $this->assertFalse($this->hierarchy->hasMethodDefinition('App\\Unknown', 'execute'));
    }

    public function testResolveMethodClassFromSameClass(): void
    {
        $this->hierarchy->addMethodDefinition('App\\MyClass', 'execute');

        $this->assertSame('App\\MyClass', $this->hierarchy->resolveMethodClass('App\\MyClass', 'execute'));
    }

    public function testResolveMethodClassFromTrait(): void
    {
        $this->hierarchy->addMethodDefinition('App\\MyTrait', 'traitMethod');
        $this->hierarchy->setClassTraits('App\\MyClass', ['App\\MyTrait']);

        $this->assertSame('App\\MyTrait', $this->hierarchy->resolveMethodClass('App\\MyClass', 'traitMethod'));
    }

    public function testResolveMethodClassFromParent(): void
    {
        $this->hierarchy->addMethodDefinition('App\\Parent', 'parentMethod');
        $this->hierarchy->setClassParent('App\\Child', 'App\\Parent');

        $this->assertSame('App\\Parent', $this->hierarchy->resolveMethodClass('App\\Child', 'parentMethod'));
    }

    public function testResolveMethodClassFromGrandparent(): void
    {
        $this->hierarchy->addMethodDefinition('App\\GrandParent', 'inheritedMethod');
        $this->hierarchy->setClassParent('App\\Parent', 'App\\GrandParent');
        $this->hierarchy->setClassParent('App\\Child', 'App\\Parent');

        $this->assertSame('App\\GrandParent', $this->hierarchy->resolveMethodClass('App\\Child', 'inheritedMethod'));
    }

    public function testResolveMethodClassPrioritizesOwnMethodOverTrait(): void
    {
        $this->hierarchy->addMethodDefinition('App\\MyClass', 'execute');
        $this->hierarchy->addMethodDefinition('App\\MyTrait', 'execute');
        $this->hierarchy->setClassTraits('App\\MyClass', ['App\\MyTrait']);

        $this->assertSame('App\\MyClass', $this->hierarchy->resolveMethodClass('App\\MyClass', 'execute'));
    }

    public function testResolveMethodClassPrioritizesTraitOverParent(): void
    {
        $this->hierarchy->addMethodDefinition('App\\MyTrait', 'execute');
        $this->hierarchy->addMethodDefinition('App\\Parent', 'execute');
        $this->hierarchy->setClassTraits('App\\Child', ['App\\MyTrait']);
        $this->hierarchy->setClassParent('App\\Child', 'App\\Parent');

        $this->assertSame('App\\MyTrait', $this->hierarchy->resolveMethodClass('App\\Child', 'execute'));
    }

    public function testResolveMethodClassReturnsNullForUnknown(): void
    {
        $this->assertNull($this->hierarchy->resolveMethodClass('App\\MyClass', 'unknownMethod'));
    }

    // ==========================================
    // Method Return Type Tests
    // ==========================================

    public function testAddAndGetMethodReturnType(): void
    {
        $this->hierarchy->addMethodReturnType('App\\Factory', 'create', 'App\\Service');

        $this->assertSame('App\\Service', $this->hierarchy->getMethodReturnType('App\\Factory', 'create'));
        $this->assertNull($this->hierarchy->getMethodReturnType('App\\Factory', 'unknown'));
        $this->assertNull($this->hierarchy->getMethodReturnType('App\\Unknown', 'create'));
    }

    public function testResolveMethodReturnTypeFromSameClass(): void
    {
        $this->hierarchy->addMethodReturnType('App\\Factory', 'create', 'App\\Service');

        $this->assertSame('App\\Service', $this->hierarchy->resolveMethodReturnType('App\\Factory', 'create'));
    }

    public function testResolveMethodReturnTypeFromTrait(): void
    {
        $this->hierarchy->addMethodReturnType('App\\FactoryTrait', 'create', 'App\\Service');
        $this->hierarchy->setClassTraits('App\\Factory', ['App\\FactoryTrait']);

        $this->assertSame('App\\Service', $this->hierarchy->resolveMethodReturnType('App\\Factory', 'create'));
    }

    public function testResolveMethodReturnTypeFromParent(): void
    {
        $this->hierarchy->addMethodReturnType('App\\AbstractFactory', 'create', 'App\\Service');
        $this->hierarchy->setClassParent('App\\ConcreteFactory', 'App\\AbstractFactory');

        $this->assertSame('App\\Service', $this->hierarchy->resolveMethodReturnType('App\\ConcreteFactory', 'create'));
    }

    public function testResolveMethodReturnTypeFromGrandparent(): void
    {
        $this->hierarchy->addMethodReturnType('App\\BaseFactory', 'create', 'App\\Service');
        $this->hierarchy->setClassParent('App\\AbstractFactory', 'App\\BaseFactory');
        $this->hierarchy->setClassParent('App\\ConcreteFactory', 'App\\AbstractFactory');

        $this->assertSame('App\\Service', $this->hierarchy->resolveMethodReturnType('App\\ConcreteFactory', 'create'));
    }

    public function testResolveMethodReturnTypePrioritizesOwnOverTrait(): void
    {
        $this->hierarchy->addMethodReturnType('App\\Factory', 'create', 'App\\ConcreteService');
        $this->hierarchy->addMethodReturnType('App\\FactoryTrait', 'create', 'App\\AbstractService');
        $this->hierarchy->setClassTraits('App\\Factory', ['App\\FactoryTrait']);

        $this->assertSame('App\\ConcreteService', $this->hierarchy->resolveMethodReturnType('App\\Factory', 'create'));
    }

    public function testResolveMethodReturnTypePrioritizesTraitOverParent(): void
    {
        $this->hierarchy->addMethodReturnType('App\\FactoryTrait', 'create', 'App\\TraitService');
        $this->hierarchy->addMethodReturnType('App\\AbstractFactory', 'create', 'App\\ParentService');
        $this->hierarchy->setClassTraits('App\\ConcreteFactory', ['App\\FactoryTrait']);
        $this->hierarchy->setClassParent('App\\ConcreteFactory', 'App\\AbstractFactory');

        $this->assertSame('App\\TraitService', $this->hierarchy->resolveMethodReturnType(
            'App\\ConcreteFactory',
            'create',
        ));
    }

    public function testResolveMethodReturnTypeReturnsNullForUnknown(): void
    {
        $this->assertNull($this->hierarchy->resolveMethodReturnType('App\\Factory', 'unknownMethod'));
    }
}
