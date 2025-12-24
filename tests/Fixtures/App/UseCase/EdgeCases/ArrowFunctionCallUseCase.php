<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called inside an arrow function
 * Arrow functions (fn() =>) automatically capture $this
 * Expected: PASS - arrow function bodies should be traversed
 */
final class ArrowFunctionCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $fn = fn() => $this->authorizer->authorize();
        $fn();
    }
}
