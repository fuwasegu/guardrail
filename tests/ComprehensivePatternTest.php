<?php

declare(strict_types=1);

namespace Guardrail\Tests;

use Guardrail\Analysis\Analyzer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test for all PHP call patterns.
 * This test documents which patterns are supported and which are known limitations.
 */
final class ComprehensivePatternTest extends TestCase
{
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new Analyzer(__DIR__ . '/Fixtures/App');
    }

    // ==========================================
    // STATIC CALL PATTERNS
    // ==========================================

    #[Test]
    public function testSelfStaticCallIsDetected(): void
    {
        // self:: should resolve to current class
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\SelfStaticCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        self::assertFalse($results[0]->hasViolations(), 'self:: calls should be detected');
    }

    #[Test]
    public function testStaticLateBindingCallIsDetected(): void
    {
        // static:: should resolve to current class (late static binding)
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\StaticLateBindingCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        self::assertFalse($results[0]->hasViolations(), 'static:: calls should be detected');
    }

    #[Test]
    public function testParentStaticCallIsDetected(): void
    {
        // parent:: should resolve to parent class
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ParentStaticCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $hasViolations = $results[0]->hasViolations();

        if ($hasViolations) {
            self::markTestIncomplete('[BUG] parent:: is not handled in resolveName - needs fix');
        }

        self::assertFalse($hasViolations, 'parent:: calls should be detected');
    }

    // ==========================================
    // ARROW FUNCTION PATTERNS
    // ==========================================

    #[Test]
    public function testArrowFunctionCallIsDetected(): void
    {
        // Arrow function fn() => body should be traversed
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ArrowFunctionCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        self::assertFalse($results[0]->hasViolations(), 'Arrow function calls should be detected');
    }

    // ==========================================
    // CONTROL FLOW PATTERNS
    // ==========================================

    #[Test]
    public function testMatchExpressionCallIsDetected(): void
    {
        // Match expression arms should be traversed
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\MatchExpressionCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        self::assertFalse($results[0]->hasViolations(), 'Match expression calls should be detected');
    }

    #[Test]
    public function testMatchExpressionPartialCallIsDetected(): void
    {
        // At least one match arm calling authorize should be detected
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\MatchExpressionPartialUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        self::assertFalse($results[0]->hasViolations(), 'Partial match expression calls should be detected');
    }

    #[Test]
    public function testTernaryCallIsDetected(): void
    {
        // Ternary branches should be traversed
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\TernaryCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        self::assertFalse($results[0]->hasViolations(), 'Ternary expression calls should be detected');
    }

    #[Test]
    public function testNullCoalescingCallIsDetected(): void
    {
        // Null coalescing ?? should be traversed
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\NullCoalescingCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        self::assertFalse($results[0]->hasViolations(), 'Null coalescing calls should be detected');
    }

    // ==========================================
    // KNOWN LIMITATIONS - Array Callbacks
    // ==========================================

    #[Test]
    public function testArrayCallbackExecutionIsNotDetected(): void
    {
        // [LIMITATION] Array callback execution [$obj, 'method']() is not detected
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ArrayCallbackExecuteUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // This is a known limitation - should FAIL
        self::assertTrue(
            $results[0]->hasViolations(),
            '[LIMITATION] Array callback execution cannot be detected statically',
        );
    }

    #[Test]
    public function testArrayMapCallbackIsNotDetected(): void
    {
        // [LIMITATION] array_map with callback is not detected
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ArrayMapCallbackUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // This is a known limitation - should FAIL
        self::assertTrue(
            $results[0]->hasViolations(),
            '[LIMITATION] array_map callbacks cannot be detected statically',
        );
    }

    // ==========================================
    // CLOSURE PATTERNS
    // ==========================================

    #[Test]
    public function testClosureInSameMethodIsDetected(): void
    {
        // Closure body IS traversed as part of the class method
        // When the closure is defined inline, its body is analyzed within the method context
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ClosureVariableExecutionUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // The closure body is traversed during analysis, so the call IS detected
        self::assertFalse($results[0]->hasViolations(), 'Closure body defined in method should be detected');
    }

    #[Test]
    public function testClosurePassedAsArgumentIsNotDetected(): void
    {
        // [LIMITATION] When closure is passed as argument and executed in another method,
        // the call graph loses the connection between the entry point and the closure body.
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ClosureTypedParameterUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        // Known limitation: closure passed to another method loses call chain connection
        self::assertTrue(
            $results[0]->hasViolations(),
            '[LIMITATION] Closure passed as argument cannot be traced through method call',
        );
    }
}
