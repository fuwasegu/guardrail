<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

final class AuthProvider
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
 * Edge case: Method parameter with chained call
 * public function foo(AuthProvider $p) { $p->getAuthorizer()->authorize(); }
 */
final class ParameterChainedCallUseCase
{
    public function execute(AuthProvider $provider): void
    {
        $provider->getAuthorizer()->authorize();
    }
}
