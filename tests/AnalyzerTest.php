<?php

declare(strict_types=1);

namespace Guardrail\Tests;

use Guardrail\Analysis\Analyzer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new Analyzer(__DIR__ . '/Fixtures/App');
    }

    public function testAnalyzeWithPassingRule(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('authorization', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\CreateUserUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->hasViolations());
        $this->assertSame(1, $results[0]->getPassedCount());
        $this->assertSame(0, $results[0]->getViolationCount());
    }

    public function testAnalyzeWithFailingRule(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('authorization', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DeleteUserUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->hasViolations());
        $this->assertSame(0, $results[0]->getPassedCount());
        $this->assertSame(1, $results[0]->getViolationCount());
    }

    public function testAnalyzeWithMixedResults(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('authorization', function (RuleBuilder $rule): void {
                $rule
                    ->entryPoints()
                    ->namespace('App\UseCase\*UseCase')
                    ->method('execute')
                    ->excluding()
                    ->namespace('App\UseCase\EdgeCases\*');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertCount(1, $results);

        // Should have some passes and some violations
        $ruleResult = $results[0];
        $this->assertGreaterThan(0, $ruleResult->getTotalCount());
    }

    public function testAnalyzeMultipleRules(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('rule-1', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\CreateUserUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->rule('rule-2', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DeleteUserUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertCount(2, $results);
        $this->assertFalse($results[0]->hasViolations()); // CreateUser passes
        $this->assertTrue($results[1]->hasViolations()); // DeleteUser fails
    }

    public function testAnalyzeWithMustCallAnyOf(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('authorization', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\CreateUserUseCase');
                $rule->mustCallAnyOf([
                    ['App\Auth\Authorizer', 'authorize'],
                    ['App\Auth\Authorizer', 'validate'],
                ]);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->hasViolations());
    }

    public function testAnalyzeReturnsCallPath(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('authorization', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\CreateUserUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $passed = $results[0]->getPassed();
        $this->assertNotEmpty($passed);
        $this->assertNotNull($passed[0]->callPath);
        $this->assertNotEmpty($passed[0]->callPath);
    }

    public function testAnalyzeViolationHasMessage(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('authorization', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\DeleteUserUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize'])->message('Must call authorize!');
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $violations = $results[0]->getViolations();
        $this->assertNotEmpty($violations);
        $this->assertSame('Must call authorize!', $violations[0]->message);
    }

    public function testAnalyzeWithIndirectCall(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('authorization', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\IndirectCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations());
    }

    public function testAnalyzeWithParentClassMethod(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('authorization', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\ParentCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations());
    }

    public function testAnalyzeWithTraitMethod(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('authorization', function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\EdgeCases\TraitCallUseCase');
                $rule->mustCall(['App\Auth\Authorizer', 'authorize']);
            })
            ->build();

        $results = $this->analyzer->analyze($rules);

        $this->assertFalse($results[0]->hasViolations());
    }
}
