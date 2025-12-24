<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

/**
 * Edge case: Clone expression
 * $x = clone $this->authorizer; $x->authorize()
 */
final class CloneExpressionUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $x = clone $this->authorizer;
        $x->authorize();
    }
}
