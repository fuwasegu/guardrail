<?php

declare(strict_types=1);

namespace Guardrail\Config;

use Closure;

/**
 * Main configuration class for Guardrail.
 *
 * @example
 * return GuardrailConfig::create()
 *     ->rule('authorization', function (RuleBuilder $rule) {
 *         $rule->entryPoints()
 *             ->namespace('App\\UseCase\\*')
 *             ->method('execute');
 *         $rule->mustCall([Authorizer::class, 'authorize'])
 *             ->atLeastOnce()
 *             ->message('All UseCases must call authorize()');
 *     })
 *     ->build();
 */
final class GuardrailConfig
{
    /** @var list<RuleBuilder> */
    private array $ruleBuilders = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * Define a new rule with a closure for configuration.
     *
     * @param string $name The rule name
     * @param Closure(RuleBuilder): void $configure Closure to configure the rule
     */
    public function rule(string $name, Closure $configure): self
    {
        $builder = new RuleBuilder($name);
        $configure($builder);
        $this->ruleBuilders[] = $builder;
        return $this;
    }

    /**
     * @return list<Rule>
     */
    public function build(): array
    {
        return array_map(static fn(RuleBuilder $builder) => $builder->buildRule(), $this->ruleBuilders);
    }

    /**
     * Load configuration from a file.
     *
     * @return list<Rule>
     */
    public static function loadFromFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Configuration file not found: {$path}");
        }

        /** @var self|list<Rule>|mixed $config */
        $config = require $path;

        if ($config instanceof self) {
            return $config->build();
        }

        if (is_array($config)) {
            /** @var list<Rule> $config */
            return $config;
        }

        throw new \InvalidArgumentException('Configuration file must return GuardrailConfig or array of Rules');
    }
}
