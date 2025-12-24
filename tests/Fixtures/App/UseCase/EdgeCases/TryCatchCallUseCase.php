<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called inside try/catch block
 * Expected: PASS - try/catch calls should be detected
 */
final class TryCatchCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        try {
            $this->authorizer->authorize();
        } catch (\Exception $e) {
            // Handle exception
        }
    }
}
