<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called only in some branches
 * Expected: PASS with atLeastOnce() (current behavior)
 * Note: With onAllPaths() this should FAIL
 */
final class ConditionalCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(bool $needsAuth): void
    {
        if ($needsAuth) {
            $this->authorizer->authorize();
        }

        // If needsAuth is false, authorize() is never called
    }
}
