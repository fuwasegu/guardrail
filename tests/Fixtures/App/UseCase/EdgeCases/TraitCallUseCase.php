<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

trait AuthorizeTrait
{
    private readonly Authorizer $authorizer;

    protected function doAuthorize(): void
    {
        $this->authorizer->authorize();
    }
}

/**
 * Edge case: authorize() is called through a trait method
 * Expected: Need to verify if trait method calls are tracked
 */
final class TraitCallUseCase
{
    use AuthorizeTrait;

    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $this->doAuthorize();
    }
}
