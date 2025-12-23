<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called dynamically
 * Test expectation: Guardrail reports violation (false negative)
 * [LIMITATION]: Dynamic method calls ($obj->$method()) cannot be analyzed statically
 */
final class DynamicCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $method = 'authorize';
        $this->authorizer->$method(); // Dynamic call - cannot detect
    }
}
