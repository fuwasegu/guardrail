<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called inside a match expression arm
 * Expected: PASS - match expression arms are traversed
 */
final class MatchExpressionCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(string $action): void
    {
        match ($action) {
            'admin' => $this->authorizer->authorize(),
            'user' => $this->authorizer->authorize(),
            default => throw new \RuntimeException('Unknown action'),
        };
    }
}

/**
 * Edge case: authorize() only in one branch of match
 * Expected: PASS - at least one path calls authorize
 */
final class MatchExpressionPartialUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(string $action): void
    {
        match ($action) {
            'admin' => $this->authorizer->authorize(),
            default => null,
        };
    }
}
