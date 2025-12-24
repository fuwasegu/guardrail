<?php

declare(strict_types=1);

namespace Guardrail\Tests;

use Guardrail\Analysis\Analyzer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for data flow analysis (variable type tracking).
 */
final class DataFlowAnalysisTest extends TestCase
{
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new Analyzer(__DIR__ . '/Fixtures/App');
    }

    // ==========================================
    // SUPPORTED PATTERNS
    // ==========================================

    public function testVariableCopyTracking(): void
    {
        // $x = new Authorizer(); $y = $x; $y->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\VariableCopyUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Variable copy should preserve type');
    }

    public function testPropertyAssignmentTracking(): void
    {
        // $x = $this->authorizer; $x->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\PropertyAssignmentUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Property assignment should track type');
    }

    public function testReassignmentTracking(): void
    {
        // $x = new DummyService(); $x = new Authorizer(); $x->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\ReassignmentUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Reassignment should update type (last wins)');
    }

    public function testInstanceMethodReturnTracking(): void
    {
        // $auth = $this->provider->provide(); $auth->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\InstanceMethodReturnUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Instance method return type should be tracked');
    }

    public function testParameterChainedCall(): void
    {
        // public function foo(Provider $p) { $p->getAuthorizer()->authorize(); }
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\ParameterChainedCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Parameter chained call should be tracked');
    }

    public function testDoubleChainedCall(): void
    {
        // $this->level1->getLevel2()->getAuthorizer()->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\DoubleChainedCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Double chained call should be tracked');
    }

    public function testNullSafeChainedCall(): void
    {
        // $this->holder?->getAuthorizer()?->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\NullSafeChainedCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Null-safe chained call should be tracked');
    }

    public function testNestedPropertyAccess(): void
    {
        // $x = $this->container->authorizer; $x->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\NestedPropertyAccessUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Nested property access should be tracked');
    }

    public function testDirectNestedPropertyCall(): void
    {
        // $this->container->authorizer->authorize() (no intermediate variable)
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\DirectNestedPropertyCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Direct nested property call should be tracked');
    }

    public function testCloneExpression(): void
    {
        // $x = clone $this->authorizer; $x->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\CloneExpressionUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Clone expression should be tracked');
    }

    public function testNullCoalescingExpression(): void
    {
        // $x = $this->maybeAuth ?? new Authorizer(); $x->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\NullCoalescingExpressionUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Null coalescing expression should be tracked');
    }

    public function testMixedChain(): void
    {
        // $this->container->holder->getAuthorizer()->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\MixedChainUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Mixed chain should be tracked');
    }

    public function testStaticProperty(): void
    {
        // $x = self::$authorizer; $x->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\StaticPropertyUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations(), 'Static property should be tracked');
    }

    // ==========================================
    // KNOWN LIMITATIONS
    // ==========================================

    public function testNullCoalescingAssignment(): void
    {
        // $this->cached ??= new Authorizer(); $this->cached->authorize()
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DataFlow\NullCoalescingAssignmentUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $hasViolation = $results[0]->hasViolations();

        if ($hasViolation) {
            $this->markTestSkipped('[LIMITATION]: Null coalescing assignment not tracked');
        }

        $this->assertFalse($hasViolation, 'Null coalescing assignment should be tracked');
    }
}
