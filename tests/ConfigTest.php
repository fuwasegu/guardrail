<?php

declare(strict_types=1);

namespace Guardrail\Tests;

use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\PathCondition;
use Guardrail\Config\RuleBuilder;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testBasicRuleCreation(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test-rule', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\*');
                $rule->mustCall([self::class, 'dummyMethod']);
            })
            ->build();

        $this->assertCount(1, $rules);
        $this->assertSame('test-rule', $rules[0]->name);
        $this->assertCount(1, $rules[0]->requiredCalls);
        $this->assertSame(PathCondition::AtLeastOnce, $rules[0]->pathCondition);
    }

    public function testMultipleRules(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('rule-1', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\Service\*');
                $rule->mustCall([self::class, 'methodA']);
            })
            ->rule('rule-2', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\*');
                $rule->mustCall([self::class, 'methodB']);
            })
            ->build();

        $this->assertCount(2, $rules);
        $this->assertSame('rule-1', $rules[0]->name);
        $this->assertSame('rule-2', $rules[1]->name);
    }

    public function testMustCallAnyOf(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\*');
                $rule->mustCallAnyOf([
                    [self::class, 'methodA'],
                    [self::class, 'methodB'],
                ]);
            })
            ->build();

        $this->assertCount(2, $rules[0]->requiredCalls);
    }

    public function testCustomMessage(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\*');
                $rule->mustCall([self::class, 'method'])->message('Custom error message');
            })
            ->build();

        $this->assertSame('Custom error message', $rules[0]->message);
        $this->assertSame('Custom error message', $rules[0]->getDisplayMessage());
    }

    public function testDefaultDisplayMessage(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\*');
                $rule->mustCall([self::class, 'authorize']);
            })
            ->build();

        $this->assertNull($rules[0]->message);
        $this->assertStringContainsString('authorize', $rules[0]->getDisplayMessage());
        $this->assertStringContainsString('at least once', $rules[0]->getDisplayMessage());
    }

    public function testEntryPointWithMethodFilter(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\*')->method('execute', 'handle');
                $rule->mustCall([self::class, 'method']);
            })
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testEntryPointWithPublicMethods(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\*')->publicMethods();
                $rule->mustCall([self::class, 'method']);
            })
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testEntryPointWithExclusion(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\*')->excluding()->namespace('App\Internal\*');
                $rule->mustCall([self::class, 'method']);
            })
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testEntryPointWithOrCondition(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\UseCase\*')->or()->namespace('App\Service\*');
                $rule->mustCall([self::class, 'method']);
            })
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testRuleWithoutEntryPointsThrowsException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have entry points');

        GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->mustCall([self::class, 'method']);
            })
            ->build();
    }

    public function testRuleWithoutRequiredCallsThrowsException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have at least one required call');

        GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\*');
            })
            ->build();
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

    public function testEntryPointWithHttpMethodFilter(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->route('routes/api.php', '/api')->httpMethod('POST', 'PUT', 'DELETE')->end();
                $rule->mustCall([self::class, 'method']);
            })
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testEntryPointWithSingleHttpMethod(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->route('routes/api.php')->httpMethod('GET')->end();
                $rule->mustCall([self::class, 'method']);
            })
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testEntryPointWithHttpMethodCaseInsensitive(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->route('routes/api.php')->httpMethod('get', 'post')->end(); // lowercase
                $rule->mustCall([self::class, 'method']);
            })
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testEntryPointCombinesHttpMethodAndExcludeRoutes(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule
                    ->entryPoints()
                    ->route('routes/api.php', '/api')
                    ->excludeRoutes('/api/login', '/api/health')
                    ->httpMethod('POST', 'PUT', 'DELETE')
                    ->end();
                $rule->mustCall([self::class, 'method']);
            })
            ->build();

        $this->assertCount(1, $rules);
    }

    public function testPairedCallsConfiguration(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\\*')->end();
                $rule->whenCalls([self::class, 'beginTransaction'])->mustAlsoCall([self::class, 'commit'], [
                    self::class,
                    'rollback',
                ])->end();
            })
            ->build();

        $this->assertCount(1, $rules);
        $this->assertCount(1, $rules[0]->pairedCallRequirements);
        $this->assertCount(2, $rules[0]->pairedCallRequirements[0]->requiredCalls);
    }

    public function testPairedCallsWithMessage(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\\*')->end();
                $rule
                    ->whenCalls([self::class, 'beginTransaction'])
                    ->mustAlsoCall([self::class, 'commit'])
                    ->message('Must commit transaction')
                    ->end();
            })
            ->build();

        $this->assertSame('Must commit transaction', $rules[0]->pairedCallRequirements[0]->message);
    }

    public function testMultiplePairedCalls(): void
    {
        $rules = GuardrailConfig::create()
            ->rule('resources', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\\*')->end();
                $rule->whenCalls([self::class, 'beginTransaction'])->mustAlsoCall([self::class, 'commit'])->end();
                $rule->whenCalls([self::class, 'acquireLock'])->mustAlsoCall([self::class, 'releaseLock'])->end();
            })
            ->build();

        $this->assertCount(1, $rules);
        $this->assertCount(2, $rules[0]->pairedCallRequirements);
    }

    public function testPairedCallsOnlyRule(): void
    {
        // Rule with ONLY whenCalls (no mustCall) should be valid
        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\\*')->end();
                $rule->whenCalls([self::class, 'beginTransaction'])->mustAlsoCall([self::class, 'commit'])->end();
            })
            ->build();

        $this->assertCount(1, $rules);
        $this->assertEmpty($rules[0]->requiredCalls);
        $this->assertCount(1, $rules[0]->pairedCallRequirements);
    }

    public function testPairedCallsWithoutRequiredCallsThrowsIfNoEnd(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Paired call requirement must have at least one required call');

        GuardrailConfig::create()
            ->rule('test', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\\*')->end();
                $rule->whenCalls([self::class, 'method'])->end(); // No mustAlsoCall
            })
            ->build();
    }

    public static function beginTransaction(): void
    {
    }

    public static function commit(): void
    {
    }

    public static function rollback(): void
    {
    }

    public static function acquireLock(): void
    {
    }

    public static function releaseLock(): void
    {
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
