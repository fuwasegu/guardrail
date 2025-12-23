<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called inside a closure
 * Expected: ??? (depends on implementation)
 */
final class ClosureCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $callback = function () {
            $this->authorizer->authorize();
        };
        $callback();
    }
}
