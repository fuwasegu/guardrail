<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Base class with authorize method for parent:: testing
 */
abstract class BaseWithAuthorize
{
    public function __construct(
        protected readonly Authorizer $authorizer,
    ) {}

    protected function doAuthorize(): void
    {
        $this->authorizer->authorize();
    }
}

/**
 * Edge case: authorize() is called via parent::
 * Expected: PASS - but currently NOT HANDLED in resolveName!
 */
final class ParentStaticCallUseCase extends BaseWithAuthorize
{
    public function execute(): void
    {
        parent::doAuthorize();
    }
}
