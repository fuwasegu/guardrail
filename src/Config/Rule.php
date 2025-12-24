<?php

declare(strict_types=1);

namespace Guardrail\Config;

use Guardrail\Collector\CollectorInterface;

/**
 * Represents a single validation rule.
 */
final class Rule
{
    /**
     * @param string $name Rule identifier
     * @param CollectorInterface $entryPointCollector Collector for entry points (exclusions are already applied)
     * @param list<MethodReference> $requiredCalls Methods that must be called
     * @param PathCondition $pathCondition How the required calls must be present
     * @param string|null $message Custom error message
     * @param list<PairedCallRequirement> $pairedCallRequirements Paired call requirements (when X is called, Y must also be called)
     */
    public function __construct(
        public readonly string $name,
        public readonly CollectorInterface $entryPointCollector,
        public readonly array $requiredCalls,
        public readonly PathCondition $pathCondition,
        public readonly ?string $message = null,
        public readonly array $pairedCallRequirements = [],
    ) {}

    public function getDisplayMessage(): string
    {
        if ($this->message !== null) {
            return $this->message;
        }

        $calls = implode(' or ', array_map(static fn($c) => (string) $c, $this->requiredCalls));

        return match ($this->pathCondition) {
            PathCondition::OnAllPaths => "Must call {$calls} on all execution paths",
            PathCondition::AtLeastOnce => "Must call {$calls} at least once",
        };
    }
}
