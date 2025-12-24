<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

interface AuthorizerInterface
{
    public function authorize(): void;
}

/**
 * Edge case: authorize() is called via interface type hint
 * Expected: PASS - interface type is tracked from property type declaration
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
