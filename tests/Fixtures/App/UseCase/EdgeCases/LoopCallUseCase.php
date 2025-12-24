<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called inside a loop
 * Expected: PASS - loop calls should be detected
 */
final class LoopCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(array $items): void
    {
        foreach ($items as $item) {
            $this->authorizer->authorize();
        }
    }
}
