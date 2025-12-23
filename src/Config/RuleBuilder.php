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

    public function buildRule(): Rule
    {
        if ($this->entryPointBuilder === null) {
            throw new \LogicException("Rule '{$this->name}' must have entry points defined");
        }

        if ($this->requiredCalls === []) {
            throw new \LogicException("Rule '{$this->name}' must have at least one required call");
        }

        return new Rule(
            name: $this->name,
            entryPointCollector: $this->entryPointBuilder->build(),
            requiredCalls: $this->requiredCalls,
            pathCondition: $this->pathCondition,
            message: $this->message,
        );
    }
}
