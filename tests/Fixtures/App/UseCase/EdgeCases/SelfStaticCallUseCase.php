<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called via self:: or static::
 * Expected: PASS - self and static are resolved to current class
 */
class SelfStaticCallUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        self::doAuthorize($this->authorizer);
    }

    private static function doAuthorize(Authorizer $authorizer): void
    {
        $authorizer->authorize();
    }
}

/**
 * Edge case: authorize() is called via static:: (late static binding)
 */
class StaticLateBindingCallUseCase extends SelfStaticCallUseCase
{
    public function execute(): void
    {
        static::doAuthorize($this->authorizer);
    }
}
