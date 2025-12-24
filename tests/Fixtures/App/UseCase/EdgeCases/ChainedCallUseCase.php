<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

final class AuthorizerHolder
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function getAuthorizer(): Authorizer
    {
        return $this->authorizer;
    }
}

/**
 * Edge case: authorize() is called via method chaining
 * [LIMITATION]: Chained method call types cannot be resolved
 */
final class ChainedCallUseCase
{
    public function __construct(
        private readonly AuthorizerHolder $holder,
    ) {}

    public function execute(): void
    {
        $this->holder->getAuthorizer()->authorize(); // Chained call - cannot detect
    }
}
