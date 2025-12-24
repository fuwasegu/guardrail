<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

/**
 * Edge case: Null coalescing expression (not assignment)
 * $x = $this->maybeAuth ?? new Authorizer(); $x->authorize()
 */
final class NullCoalescingExpressionUseCase
{
    public function __construct(
        private readonly ?Authorizer $maybeAuth = null,
    ) {}

    public function execute(): void
    {
        $x = $this->maybeAuth ?? new Authorizer();
        $x->authorize();
    }
}
