<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

interface AuthorizerInterface
{
    public function authorize(): void;
}

/**
 * Edge case: authorize() is called via interface type hint
 * Test expectation: Guardrail reports violation (false negative)
 * [LIMITATION]: Interface type hints cannot be resolved to concrete implementations
 */
final class InterfaceCallUseCase
{
    public function __construct(
        private readonly AuthorizerInterface $authorizer,
    ) {}

    public function execute(): void
    {
        $this->authorizer->authorize();
    }
}
