<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called via call_user_func
 * Test expectation: Guardrail reports violation (false negative)
 * [LIMITATION]: call_user_func() cannot be analyzed statically
 */
final class CallUserFuncUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        call_user_func([$this->authorizer, 'authorize']);
    }
}
