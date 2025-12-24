<?php

declare(strict_types=1);

namespace Guardrail\Analysis;

use Guardrail\Analysis\CallGraph\MethodCall;
use Guardrail\Collector\EntryPoint;
use Guardrail\Config\PairedCallRequirement;

/**
 * Represents a violation of a paired call requirement.
 *
 * This occurs when the trigger method is called but none of the required methods are called.
 */
final readonly class PairedCallViolation
{
    /**
     * @param list<MethodCall> $triggerPath Path from entry point to the trigger call
     */
    public function __construct(
        public EntryPoint $entryPoint,
        public PairedCallRequirement $requirement,
        public array $triggerPath,
    ) {}

    public function getMessage(): string
    {
        return $this->requirement->getDisplayMessage();
    }
}
