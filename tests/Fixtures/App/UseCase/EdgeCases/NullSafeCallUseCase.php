<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called using null-safe operator
 * Expected: PASS - null-safe operator calls ARE detected
 */
final class NullSafeCallUseCase
{
    public function __construct(
        private readonly ?Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $this->authorizer?->authorize();
    }
}
