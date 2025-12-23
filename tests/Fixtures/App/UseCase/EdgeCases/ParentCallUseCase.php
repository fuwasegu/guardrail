<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

abstract class AbstractAuthorizedUseCase
{
    public function __construct(
        protected readonly Authorizer $authorizer,
    ) {}

    protected function ensureAuthorized(): void
    {
        $this->authorizer->authorize();
    }
}

/**
 * Edge case: authorize() is called via parent class method
 * Expected: PASS (authorize is called via parent)
 * Status: PASS - Parent class method resolution implemented
 */
final class ParentCallUseCase extends AbstractAuthorizedUseCase
{
    public function execute(): void
    {
        $this->ensureAuthorized();
    }
}
