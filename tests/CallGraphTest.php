<?php

declare(strict_types=1);

namespace Guardrail\Tests;

use Guardrail\Analysis\CallGraph\CallGraph;
use Guardrail\Analysis\CallGraph\CallGraphBuilder;
use PHPUnit\Framework\TestCase;

final class CallGraphTest extends TestCase
{
    private static CallGraph $callGraph;

    public static function setUpBeforeClass(): void
    {
        $builder = new CallGraphBuilder();
        self::$callGraph = $builder->build(__DIR__ . '/Fixtures/App');
    }

    private function assertPathExists(string $fromClass, string $fromMethod, string $toClass, string $toMethod): void
    {
        $path = self::$callGraph->findPathTo($fromClass, $fromMethod, $toClass, $toMethod);
        $this->assertNotNull($path, "Expected path from {$fromClass}::{$fromMethod} to {$toClass}::{$toMethod}");
    }

    private function assertPathNotExists(string $fromClass, string $fromMethod, string $toClass, string $toMethod): void
    {
        $path = self::$callGraph->findPathTo($fromClass, $fromMethod, $toClass, $toMethod);
        $this->assertNull($path, "Expected NO path from {$fromClass}::{$fromMethod} to {$toClass}::{$toMethod}");
    }

    // ========================================
    // Cases that SHOULD detect authorize call
    // ========================================

    public function testDirectCall(): void
    {
        $this->assertPathExists('App\UseCase\CreateUserUseCase', 'execute', 'App\Auth\Authorizer', 'authorize');
    }

    public function testIndirectCall(): void
    {
        $this->assertPathExists(
            'App\UseCase\EdgeCases\IndirectCallUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }

    public function testConditionalCall(): void
    {
        $this->assertPathExists(
            'App\UseCase\EdgeCases\ConditionalCallUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }

    public function testParentClassMethodCall(): void
    {
        $this->assertPathExists(
            'App\UseCase\EdgeCases\ParentCallUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }

    public function testTraitMethodCall(): void
    {
        $this->assertPathExists(
            'App\UseCase\EdgeCases\TraitCallUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }

    public function testGrandparentMethodCall(): void
    {
        $this->assertPathExists(
            'App\UseCase\EdgeCases\GrandparentCallUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }

    public function testParentTraitPropertyResolution(): void
    {
        $this->assertPathExists(
            'App\UseCase\EdgeCases\ParentTraitUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }

    // ========================================
    // Cases that SHOULD NOT detect authorize call
    // (Guardrail correctly identifies violations)
    // ========================================

    public function testNoAuthorizationCall(): void
    {
        $this->assertPathNotExists('App\UseCase\DeleteUserUseCase', 'execute', 'App\Auth\Authorizer', 'authorize');
    }

    public function testWrongClassSameMethodName(): void
    {
        // SameMethodNameUseCase calls FakeAuthorizer::authorize(), not App\Auth\Authorizer::authorize()
        $this->assertPathNotExists(
            'App\UseCase\EdgeCases\SameMethodNameUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }

    // ========================================
    // Known limitations (false negatives)
    // These SHOULD detect but CAN'T due to static analysis limits
    // ========================================

    /**
     * @group limitations
     */
    public function testDynamicMethodCallLimitation(): void
    {
        // $this->authorizer->$method() cannot be analyzed statically
        $this->assertPathNotExists(
            'App\UseCase\EdgeCases\DynamicCallUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }

    /**
     * @group limitations
     */
    public function testInterfaceTypeHintLimitation(): void
    {
        // Interface type hints cannot be resolved to concrete implementations
        $this->assertPathNotExists(
            'App\UseCase\EdgeCases\InterfaceCallUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }

    /**
     * @group limitations
     */
    public function testCallUserFuncLimitation(): void
    {
        // call_user_func() cannot be analyzed statically
        $this->assertPathNotExists(
            'App\UseCase\EdgeCases\CallUserFuncUseCase',
            'execute',
            'App\Auth\Authorizer',
            'authorize',
        );
    }
}
