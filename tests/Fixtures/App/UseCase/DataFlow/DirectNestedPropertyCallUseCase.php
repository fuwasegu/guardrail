<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

/**
 * Edge case: Direct chained property access to method call (no intermediate variable)
 * $this->container->authorizer->authorize()
 */
final class DirectNestedPropertyCallUseCase
{
    public function __construct(
        private readonly AuthorizerContainer $container,
    ) {}

    public function execute(): void
    {
        // This is property chain → method (NOT method chain)
        $this->container->authorizer->authorize();
    }
}
