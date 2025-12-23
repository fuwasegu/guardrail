<?php

declare(strict_types=1);

namespace Guardrail\Tests;

use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\MethodReference;
use Guardrail\Config\PathCondition;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testBasicRuleCreation(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test-rule')
            ->entryPoints()
            ->namespace('App\UseCase\*')
            ->mustCall([self::class, 'dummyMethod'])
            ->build();

        $this->assertCount(1, $rules);
        $this->assertSame('test-rule', $rules[0]->name);
        $this->assertCount(1, $rules[0]->requiredCalls);
        $this->assertSame(PathCondition::AtLeastOnce, $rules[0]->pathCondition);
    }

    public function testMultipleRules(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('rule-1')
            ->entryPoints()
            ->namespace('App\Service\*')
            ->mustCall([self::class, 'methodA'])
            ->rule('rule-2')
            ->entryPoints()
            ->namespace('App\UseCase\*')
            ->mustCall([self::class, 'methodB'])
            ->build();

        $this->assertCount(2, $rules);
        $this->assertSame('rule-1', $rules[0]->name);
        $this->assertSame('rule-2', $rules[1]->name);
    }

    public function testMustCallAnyOf(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test')
            ->entryPoints()
            ->namespace('App\*')
            ->mustCallAnyOf([
                [self::class, 'methodA'],
                [self::class, 'methodB'],
            ])
            ->build();

        $this->assertCount(2, $rules[0]->requiredCalls);
    }

    public function testCustomMessage(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test')
            ->entryPoints()
            ->namespace('App\*')
            ->mustCall([self::class, 'method'])
            ->message('Custom error message')
            ->build();

        $this->assertSame('Custom error message', $rules[0]->message);
        $this->assertSame('Custom error message', $rules[0]->getDisplayMessage());
    }

    public function testDefaultDisplayMessage(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test')
            ->entryPoints()
            ->namespace('App\*')
            ->mustCall([self::class, 'authorize'])
            ->build();

        $this->assertNull($rules[0]->message);
        $this->assertStringContainsString('authorize', $rules[0]->getDisplayMessage());
        $this->assertStringContainsString('at least once', $rules[0]->getDisplayMessage());
    }

    public function testEntryPointWithMethodFilter(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test')
            ->entryPoints()
            ->namespace('App\*')
            ->method('execute', 'handle')
            ->mustCall([self::class, 'method'])
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testEntryPointWithPublicMethods(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test')
            ->entryPoints()
            ->namespace('App\*')
            ->publicMethods()
            ->mustCall([self::class, 'method'])
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testEntryPointWithExclusion(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test')
            ->entryPoints()
            ->namespace('App\*')
            ->excluding()
            ->namespace('App\Internal\*')
            ->mustCall([self::class, 'method'])
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testEntryPointWithOrCondition(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test')
            ->entryPoints()
            ->namespace('App\UseCase\*')
            ->or()
            ->namespace('App\Service\*')
            ->mustCall([self::class, 'method'])
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testRuleWithoutEntryPointsThrowsException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have entry points');

        GuardrailConfig::create()
            ->rule('test')
            ->mustCall([self::class, 'method'])
            ->build();
    }

    /**
     * The fluent API design ensures that mustCall() must be called before build()
     * can be reached. This test documents that creating a rule without required
     * calls results in an exception.
     *
     * We use reflection to test the internal validation directly since the public
     * API doesn't allow navigating to build() without first setting required calls.
     */
    public function testRuleWithoutRequiredCallsThrowsException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have at least one required call');

        $config = GuardrailConfig::create();
        $ruleBuilder = $config->rule('test');

        // Set entry points but not required calls
        $ruleBuilder->entryPoints()->namespace('App\*');

        // Call buildRule() directly (normally called internally by build())
        $ruleBuilder->buildRule();
    }

    public function testLoadFromFile(): void
    {
        $rules = GuardrailConfig::loadFromFile(__DIR__ . '/Fixtures/guardrail.config.php');

        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);
    }

    public function testLoadFromNonExistentFileThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        GuardrailConfig::loadFromFile('/nonexistent/path.php');
    }

    public function dummyMethod(): void
    {
    }

    public static function methodA(): void
    {
    }

    public static function methodB(): void
    {
    }

    public static function method(): void
    {
    }

    public static function authorize(): void
    {
    }
}
