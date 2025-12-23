<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

trait AuthorizableTrait
{
    private Authorizer $authorizer;

    protected function doAuthorize(): void
    {
        $this->authorizer->authorize();
    }
}

/**
 * Edge case: authorize() is called via trait method
 * Expected: PASS (authorize is called via trait)
 * Status: PASS - Trait method resolution implemented
 */
final class TraitCallUseCase
{
    use AuthorizableTrait;

    public function __construct(Authorizer $authorizer)
    {
        $this->authorizer = $authorizer;
    }

    public function execute(): void
    {
        $this->doAuthorize();
    }
}
