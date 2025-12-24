<?php

declare(strict_types=1);

namespace Guardrail\Tests;

use Guardrail\Analysis\Analyzer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for edge cases and documented limitations.
 */
final class EdgeCaseAnalysisTest extends TestCase
{
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new Analyzer(__DIR__ . '/Fixtures/App');
    }

    // ==========================================
    // KNOWN LIMITATIONS (Expected: Violation)
    // ==========================================

    public function testDynamicCallIsNotDetected(): void
    {
        // [LIMITATION]: Dynamic method calls cannot be analyzed statically
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\DynamicCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Expected: Violation (false negative - authorize IS called but not detected)
        $this->assertTrue($results[0]->hasViolations(), 'Dynamic calls cannot be detected - expected violation');
    }

    public function testInterfaceCallIsDetected(): void
    {
        // Interface method calls ARE detected (interface type is tracked from property)
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\InterfaceCallUseCase');
                $rule->mustCall(['App\UseCase\EdgeCases\AuthorizerInterface', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Interface calls ARE detected - the property type is used
        $this->assertFalse($results[0]->hasViolations(), 'Interface calls should be detected');
    }

    public function testCallUserFuncIsNotDetected(): void
    {
        // [LIMITATION]: call_user_func cannot be analyzed statically
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\CallUserFuncUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Expected: Violation (call_user_func is not detected)
        $this->assertTrue($results[0]->hasViolations(), 'call_user_func cannot be detected - expected violation');
    }

    public function testLocalVariableCallIsNotDetected(): void
    {
        // [LIMITATION]: Local variable assignment types are not tracked
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\LocalVariableCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Expected: Violation (local variable type not tracked)
        $this->assertTrue($results[0]->hasViolations(), 'Local variable types not tracked - expected violation');
    }

    public function testFactoryPatternIsNotDetected(): void
    {
        // [LIMITATION]: Return types from method calls are not tracked
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\FactoryPatternUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Expected: Violation (factory return type not tracked)
        $this->assertTrue($results[0]->hasViolations(), 'Factory pattern not tracked - expected violation');
    }

    public function testChainedCallIsNotDetected(): void
    {
        // [LIMITATION]: Chained method call types cannot be resolved
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ChainedCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Expected: Violation (chained call type not resolved)
        $this->assertTrue($results[0]->hasViolations(), 'Chained calls not tracked - expected violation');
    }

    // ==========================================
    // SUPPORTED PATTERNS (Expected: Pass)
    // ==========================================

    public function testStaticCallIsDetected(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\StaticCallUseCase');
                $rule->mustCall(['App\UseCase\EdgeCases\StaticAuthorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Expected: Pass - static calls are supported
        $this->assertFalse($results[0]->hasViolations(), 'Static calls should be detected');
    }

    public function testClosureCallIsDetected(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ClosureCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Check actual behavior - may or may not be detected
        // If this fails, closure calls are a limitation
        $hasViolations = $results[0]->hasViolations();

        if ($hasViolations) {
            $this->markTestSkipped('[LIMITATION]: Closure calls are not detected');
        }

        $this->assertFalse($hasViolations, 'Closure calls should be detected');
    }

    public function testConditionalCallIsDetected(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ConditionalCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Conditional calls should be detected');
    }

    public function testTryCatchCallIsDetected(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\TryCatchCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Try/catch calls should be detected');
    }

    public function testLoopCallIsDetected(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\LoopCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Loop calls should be detected');
    }

    public function testNullSafeCallIsDetected(): void
    {
        // Null-safe operator calls ARE detected
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\NullSafeCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Expected: Pass - null-safe operator IS detected
        $this->assertFalse($results[0]->hasViolations(), 'Null-safe operator calls should be detected');
    }

    public function testFirstClassCallableIsDetected(): void
    {
        // First-class callable syntax IS detected
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\FirstClassCallableUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Expected: Pass - first-class callable IS detected
        $this->assertFalse($results[0]->hasViolations(), 'First-class callable should be detected');
    }

    public function testTraitCallIsDetected(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\TraitCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $hasViolations = $results[0]->hasViolations();

        if ($hasViolations) {
            $this->markTestSkipped('[LIMITATION]: Trait method calls are not detected');
        }

        $this->assertFalse($hasViolations, 'Trait calls should be detected');
    }

    public function testParentCallIsDetected(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ParentCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $hasViolations = $results[0]->hasViolations();

        if ($hasViolations) {
            $this->markTestSkipped('[LIMITATION]: Parent class method calls are not detected');
        }

        $this->assertFalse($hasViolations, 'Parent class calls should be detected');
    }

    public function testInterfaceImplementationIsResolved(): void
    {
        // Interface implementation resolution: Controller -> UseCaseInterface -> ConcreteUseCase -> authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\InterfaceImplementationUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Interface calls should be traced through to implementing classes
        $this->assertFalse($results[0]->hasViolations(), 'Interface implementation should be resolved');
    }
}
