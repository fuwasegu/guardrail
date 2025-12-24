<?php

declare(strict_types=1);

namespace Guardrail\Analysis;

use Guardrail\Config\Rule;

/**
 * Result of checking a single rule.
 */
final class RuleResult
{
    /** @var list<AnalysisResult>|null */
    private ?array $cachedViolations = null;

    /** @var list<AnalysisResult>|null */
    private ?array $cachedPassed = null;

    /**
     * @param list<AnalysisResult> $results
     * @param list<PairedCallViolation> $pairedCallViolations
     */
    public function __construct(
        public readonly Rule $rule,
        public readonly array $results,
        public readonly array $pairedCallViolations = [],
    ) {}

    public function hasViolations(): bool
    {
        return $this->getViolationCount() > 0 || $this->pairedCallViolations !== [];
    }

    /**
     * @return list<AnalysisResult>
     */
    public function getViolations(): array
    {
        return $this->cachedViolations ??= array_values(array_filter(
            $this->results,
            static fn(AnalysisResult $r) => $r->isViolation(),
        ));
    }

    /**
     * @return list<AnalysisResult>
     */
    public function getPassed(): array
    {
        return $this->cachedPassed ??= array_values(array_filter(
            $this->results,
            static fn(AnalysisResult $r) => !$r->isViolation(),
        ));
    }

    public function getViolationCount(): int
    {
        return count($this->getViolations());
    }

    public function getPairedViolationCount(): int
    {
        return count($this->pairedCallViolations);
    }

    /**
     * Get total violation count (regular + paired call violations).
     */
    public function getTotalViolationCount(): int
    {
        return $this->getViolationCount() + $this->getPairedViolationCount();
    }

    public function getPassedCount(): int
    {
        return count($this->getPassed());
    }

    public function getTotalCount(): int
    {
        return count($this->results);
    }
}
