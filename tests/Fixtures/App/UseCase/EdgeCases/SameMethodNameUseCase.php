<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

/**
 * A different class with the same method name as Authorizer
 */
final class FakeAuthorizer
{
    public function authorize(): void
    {
        // This does NOT check authorization!
    }
}

/**
 * Edge case: calling authorize() on wrong class
 * Test expectation: Guardrail correctly reports violation
 * (FakeAuthorizer::authorize is not App\Auth\Authorizer::authorize)
 */
final class SameMethodNameUseCase
{
    public function __construct(
        private readonly FakeAuthorizer $fake,
    ) {}

    public function execute(): void
    {
        // This is NOT the real authorize()
        $this->fake->authorize();
    }
}
