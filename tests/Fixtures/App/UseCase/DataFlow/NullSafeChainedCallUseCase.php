<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

final class NullableAuthHolder
{
    public function __construct(
        private readonly ?Authorizer $authorizer = null,
    ) {}

    public function getAuthorizer(): ?Authorizer
    {
        return $this->authorizer;
    }
}

/**
 * Edge case: Null-safe chained method calls
 * $this->holder?->getAuthorizer()?->authorize()
 */
final class NullSafeChainedCallUseCase
{
    public function __construct(
        private readonly ?NullableAuthHolder $holder = null,
    ) {}

    public function execute(): void
    {
        $this->holder?->getAuthorizer()?->authorize();
    }
}
