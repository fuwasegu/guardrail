<?php

declare(strict_types=1);

namespace Guardrail\Config;

use Closure;

/**
 * Main configuration class for Guardrail.
 *
 * @example
 * return GuardrailConfig::create()
 *     ->paths(['src', 'app'])           // Directories to scan
 *     ->exclude(['vendor', 'tests'])    // Directories to exclude
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

    /** @var list<string> */
    private array $scanPaths = ['src', 'app'];

    /** @var list<string> */
    private array $excludePaths = ['vendor'];

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * Set directories to scan for PHP files.
     * Default: ['src', 'app']
     *
     * @param list<string> $paths Paths relative to project root
     */
    public function paths(array $paths): self
    {
        $this->scanPaths = $paths;
        return $this;
    }

    /**
     * Set directories/patterns to exclude from scanning.
     * Default: ['vendor']
     *
     * @param list<string> $excludes Paths or patterns to exclude
     */
    public function exclude(array $excludes): self
    {
        $this->excludePaths = $excludes;
        return $this;
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
     * Build the configuration and return rules.
     * For backward compatibility, returns rules array.
     * Use buildConfig() to get full AnalysisConfig.
     *
     * @return list<Rule>
     */
    public function build(): array
    {
        return array_map(static fn(RuleBuilder $builder) => $builder->buildRule(), $this->ruleBuilders);
    }

    /**
     * Build the full analysis configuration including scan settings.
     */
    public function buildConfig(): AnalysisConfig
    {
        return new AnalysisConfig(
            rules: $this->build(),
            scanConfig: new ScanConfig(paths: $this->scanPaths, excludes: $this->excludePaths),
        );
    }

    /**
     * Load configuration from a file.
     *
     * @return list<Rule>
     * @deprecated Use loadConfigFromFile() instead to get scan configuration
     */
    public static function loadFromFile(string $path): array
    {
        $config = self::loadConfigFromFile($path);
        return $config->rules;
    }

    /**
     * Load full configuration from a file including scan settings.
     */
    public static function loadConfigFromFile(string $path): AnalysisConfig
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Configuration file not found: {$path}");
        }

        /** @var self|AnalysisConfig|list<Rule>|mixed $config */
        $config = require $path;

        if ($config instanceof self) {
            return $config->buildConfig();
        }

        if ($config instanceof AnalysisConfig) {
            return $config;
        }

        if (is_array($config)) {
            /** @var list<Rule> $config */
            return new AnalysisConfig(rules: $config, scanConfig: ScanConfig::default());
        }

        throw new \InvalidArgumentException(
            'Configuration file must return GuardrailConfig, AnalysisConfig, or array of Rules',
        );
    }
}
