<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

final class AuthorizerContainer
{
    public function __construct(
        public readonly Authorizer $authorizer,
    ) {}
}

/**
 * Edge case: $x = $this->container->authorizer; $x->authorize()
 * Supported via data flow analysis (nested property access tracked).
 */
final class NestedPropertyAccessUseCase
{
    public function __construct(
        private readonly AuthorizerContainer $container,
    ) {}

    public function execute(): void
    {
        $x = $this->container->authorizer;
        $x->authorize();
    }
}
