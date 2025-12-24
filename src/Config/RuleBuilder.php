<?php

declare(strict_types=1);

namespace Guardrail\Config;

/**
 * Fluent builder for configuring a rule.
 */
final class RuleBuilder
{
    private ?EntryPointBuilder $entryPointBuilder = null;

    /** @var list<MethodReference> */
    private array $requiredCalls = [];

    /** @var list<PairedCallRequirement> */
    private array $pairedCallRequirements = [];

    private PathCondition $pathCondition = PathCondition::AtLeastOnce;

    private ?string $message = null;

    public function __construct(
        private readonly string $name,
    ) {}

    public function entryPoints(): EntryPointBuilder
    {
        $this->entryPointBuilder = new EntryPointBuilder($this);
        return $this->entryPointBuilder;
    }

    /**
     * @param array{0: class-string, 1: string} $method
     */
    public function mustCall(array $method): self
    {
        $this->requiredCalls = [MethodReference::fromArray($method)];
        return $this;
    }

    /**
     * @param list<array{0: class-string, 1: string}> $methods
     */
    public function mustCallAnyOf(array $methods): self
    {
        $this->requiredCalls = array_map(MethodReference::fromArray(...), $methods);
        return $this;
    }

    /**
     * @deprecated OnAllPaths is not yet implemented. Currently behaves the same as atLeastOnce().
     */
    public function onAllPaths(): self
    {
        trigger_error(
            'PathCondition::OnAllPaths is not yet implemented. Currently behaves the same as atLeastOnce().',
            E_USER_WARNING,
        );
        $this->pathCondition = PathCondition::OnAllPaths;
        return $this;
    }

    public function atLeastOnce(): self
    {
        $this->pathCondition = PathCondition::AtLeastOnce;
        return $this;
    }

    public function message(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Define a paired call requirement: when the trigger method is called,
     * at least one of the required methods must also be called.
     *
     * Example:
     *   $rule->whenCalls([DB::class, 'beginTransaction'])
     *       ->mustAlsoCall([DB::class, 'commit'], [DB::class, 'rollback'])
     *       ->end();
     *
     * @param array{0: class-string, 1: string} $trigger The method that triggers this requirement
     */
    public function whenCalls(array $trigger): PairedCallBuilder
    {
        return new PairedCallBuilder($this, MethodReference::fromArray($trigger));
    }

    /**
     * @internal Used by PairedCallBuilder
     */
    public function addPairedCallRequirement(PairedCallRequirement $requirement): void
    {
        $this->pairedCallRequirements[] = $requirement;
    }

    public function buildRule(): Rule
    {
        if ($this->entryPointBuilder === null) {
            throw new \LogicException("Rule '{$this->name}' must have entry points defined");
        }

        if ($this->requiredCalls === [] && $this->pairedCallRequirements === []) {
            throw new \LogicException(
                "Rule '{$this->name}' must have at least one required call or paired call requirement",
            );
        }

        return new Rule(
            name: $this->name,
            entryPointCollector: $this->entryPointBuilder->build(),
            requiredCalls: $this->requiredCalls,
            pathCondition: $this->pathCondition,
            message: $this->message,
            pairedCallRequirements: $this->pairedCallRequirements,
        );
    }
}
