<?php

declare(strict_types=1);

namespace Guardrail\Config;

/**
 * Complete analysis configuration including rules and scan settings.
 */
final class AnalysisConfig
{
    /**
     * @param list<Rule> $rules
     */
    public function __construct(
        public readonly array $rules,
        public readonly ScanConfig $scanConfig,
    ) {}
}
