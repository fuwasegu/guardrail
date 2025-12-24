<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

final class AuthorizerHolder
{
    public function __construct(
        public readonly Authorizer $authorizer,
    ) {}

    public function getAuthorizer(): Authorizer
    {
        return $this->authorizer;
    }
}

final class HolderContainer
{
    public function __construct(
        public readonly AuthorizerHolder $holder,
    ) {}
}

/**
 * Edge case: Mixed chain (property → method)
 * $this->container->holder->getAuthorizer()->authorize()
 */
final class MixedChainUseCase
{
    public function __construct(
        private readonly HolderContainer $container,
    ) {}

    public function execute(): void
    {
        // property → property → method → method
        $this->container->holder->getAuthorizer()->authorize();
    }
}
