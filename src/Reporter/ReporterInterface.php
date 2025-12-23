<?php

declare(strict_types=1);

namespace Guardrail\Reporter;

use Guardrail\Analysis\RuleResult;

interface ReporterInterface
{
    /**
     * @param list<RuleResult> $results
     */
    public function report(array $results): int;
}
