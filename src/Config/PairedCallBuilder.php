<?php

declare(strict_types=1);

namespace Guardrail\Config;

/**
 * Fluent builder for configuring paired call requirements.
 */
final class PairedCallBuilder
{
    /** @var list<MethodReference> */
    private array $requiredCalls = [];

    private ?string $message = null;

    public function __construct(
        private readonly RuleBuilder $parent,
        private readonly MethodReference $trigger,
    ) {}

    /**
     * Specify the methods that must also be called when the trigger is called.
     * Any one of the specified methods satisfies the requirement.
     *
     * @param array{0: class-string, 1: string} ...$methods
     */
    public function mustAlsoCall(array ...$methods): self
    {
        foreach ($methods as $method) {
            $this->requiredCalls[] = MethodReference::fromArray($method);
        }
        return $this;
    }

    /**
     * Set a custom error message for this paired call requirement.
     */
    public function message(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Build the paired call requirement and return to the parent rule builder.
     */
    public function end(): RuleBuilder
    {
        if ($this->requiredCalls === []) {
            throw new \LogicException('Paired call requirement must have at least one required call');
        }

        $requirement = new PairedCallRequirement(
            trigger: $this->trigger,
            requiredCalls: $this->requiredCalls,
            message: $this->message,
        );

        $this->parent->addPairedCallRequirement($requirement);

        return $this->parent;
    }
}
