<?php

declare(strict_types=1);

namespace Guardrail\Analysis;

use Guardrail\Analysis\CallGraph\MethodCall;
use Guardrail\Collector\EntryPoint;
use Guardrail\Config\MethodReference;

/**
 * Result of analyzing a single entry point.
 */
final readonly class AnalysisResult
{
    /**
     * @param list<MethodCall>|null $callPath Path to the required call, null if not found
     */
    public function __construct(
        public EntryPoint $entryPoint,
        public MethodReference $requiredCall,
        public bool $found,
        public ?array $callPath = null,
        public ?string $message = null,
    ) {}

    public function isViolation(): bool
    {
        return !$this->found;
    }
}
