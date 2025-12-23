<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

abstract class GrandparentUseCase
{
    public function __construct(
        protected readonly Authorizer $authorizer,
    ) {}

    protected function doAuthorize(): void
    {
        $this->authorizer->authorize();
    }
}

abstract class MiddleUseCase extends GrandparentUseCase
{
    // No override - relies on grandparent's doAuthorize()
}

/**
 * Edge case: authorize() is called via grandparent class method (2 levels up)
 * Expected: PASS (should resolve through inheritance chain)
 * Status: PASS - Multi-level inheritance resolution works
 */
final class GrandparentCallUseCase extends MiddleUseCase
{
    public function execute(): void
    {
        $this->doAuthorize();
    }
}
