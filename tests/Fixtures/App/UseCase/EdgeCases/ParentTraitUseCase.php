<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

trait ParentAuthTrait
{
    protected function authorizeViaParentTrait(): void
    {
        $this->authorizer->authorize();
    }
}

abstract class ParentWithTrait
{
    use ParentAuthTrait;

    public function __construct(
        protected readonly Authorizer $authorizer,
    ) {}
}

/**
 * Edge case: authorize() is called via trait used in parent class
 * Expected: PASS
 * Status: PASS - Trait property resolution searches using classes
 */
final class ParentTraitUseCase extends ParentWithTrait
{
    public function execute(): void
    {
        $this->authorizeViaParentTrait();
    }
}
