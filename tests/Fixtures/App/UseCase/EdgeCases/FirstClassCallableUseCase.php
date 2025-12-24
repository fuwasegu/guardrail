<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called via first-class callable syntax
 * Expected: PASS - first-class callable references ARE detected
 */
final class FirstClassCallableUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $callable = $this->authorizer->authorize(...);
        $callable();
    }
}
