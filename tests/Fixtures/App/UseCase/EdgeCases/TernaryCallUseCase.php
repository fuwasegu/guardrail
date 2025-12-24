<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called in ternary expression
 * Expected: PASS - ternary branches are traversed
 */
final class TernaryCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(bool $shouldAuth): void
    {
        $shouldAuth ? $this->authorizer->authorize() : null;
    }
}

/**
 * Edge case: authorize() with null coalescing
 * Expected: PASS - null coalescing is traversed
 */
final class NullCoalescingCallUseCase
{
    public function __construct(
        private readonly ?Authorizer $authorizer,
        private readonly Authorizer $fallback,
    ) {}

    public function execute(): void
    {
        $this->authorizer?->authorize() ?? $this->fallback->authorize();
    }
}
