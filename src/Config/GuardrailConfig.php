<?php

declare(strict_types=1);

namespace Guardrail\Config;

/**
 * Main configuration class for Guardrail.
 *
 * @example
 * return GuardrailConfig::create()
 *     ->rule('authorization')
 *         ->entryPoints()
 *             ->namespace('App\\UseCase\\*')
 *         ->mustCall([Authorizer::class, 'authorize'])
 *         ->atLeastOnce()
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

    public function rule(string $name): RuleBuilder
    {
        $builder = new RuleBuilder($this, $name);
        $this->ruleBuilders[] = $builder;
        return $builder;
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
