<?php

declare(strict_types=1);

namespace Guardrail\Analysis;

final readonly class AnalyzerProgress
{
    private function __construct(
        public ProgressPhase $phase,
        public ?string $ruleName = null,
        public int $current = 0,
        public int $total = 0,
    ) {}

    public static function buildingCallGraph(): self
    {
        return new self(ProgressPhase::BuildingCallGraph);
    }

    public static function callGraphBuilt(): self
    {
        return new self(ProgressPhase::CallGraphBuilt);
    }

    public static function analyzingRule(string $ruleName, int $current, int $total): self
    {
        return new self(ProgressPhase::AnalyzingRule, $ruleName, $current, $total);
    }

    public function getMessage(): string
    {
        return match ($this->phase) {
            ProgressPhase::BuildingCallGraph => 'Building call graph...',
            ProgressPhase::CallGraphBuilt => 'Call graph built',
            ProgressPhase::AnalyzingRule => sprintf(
                'Analyzing rule: %s (%d/%d)',
                $this->ruleName ?? '',
                $this->current,
                $this->total,
            ),
        };
    }
}

enum ProgressPhase
{
    case BuildingCallGraph;
    case CallGraphBuilt;
    case AnalyzingRule;
}
