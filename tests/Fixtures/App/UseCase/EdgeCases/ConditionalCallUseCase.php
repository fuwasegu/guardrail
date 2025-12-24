<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called inside conditional branches
 * Expected: PASS - conditional calls should be detected
 */
final class ConditionalCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(bool $flag): void
    {
        if ($flag) {
            $this->authorizer->authorize();
        } else {
            // Some other logic
        }
    }
}
