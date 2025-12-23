<?php

declare(strict_types=1);

namespace Guardrail\Config;

enum PathCondition: string
{
    /**
     * Required call must be present on ALL execution paths.
     * If any branch can skip the call, it's a violation.
     *
     * WARNING: Not yet implemented. Currently behaves the same as AtLeastOnce.
     * Proper implementation requires Control Flow Graph (CFG) analysis.
     */
    case OnAllPaths = 'on_all_paths';

    /**
     * Required call must be present at least once in the call chain.
     * Any single path reaching the call is sufficient.
     */
    case AtLeastOnce = 'at_least_once';
}
