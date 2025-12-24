<?php

declare(strict_types=1);

namespace Guardrail\Config;

/**
 * Represents a paired call requirement.
 *
 * When the trigger method is called, at least one of the required methods must also be called.
 * Example: DB::beginTransaction() → DB::commit() or DB::rollback()
 */
final class PairedCallRequirement
{
    /**
     * @param MethodReference $trigger The method that triggers this requirement
     * @param list<MethodReference> $requiredCalls Methods that must be called when trigger is called (any one of them)
     * @param string|null $message Custom error message
     */
    public function __construct(
        public readonly MethodReference $trigger,
        public readonly array $requiredCalls,
        public readonly ?string $message = null,
    ) {}

    public function getDisplayMessage(): string
    {
        if ($this->message !== null) {
            return $this->message;
        }

        $required = implode(' or ', array_map(static fn($c) => (string) $c, $this->requiredCalls));

        return "When calling {$this->trigger}, must also call {$required}";
    }
}
