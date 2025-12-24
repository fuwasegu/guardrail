<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

/**
 * Edge case: $x ??= new Authorizer()
 * Tests null coalescing assignment operator.
 */
final class NullCoalescingAssignmentUseCase
{
    private ?Authorizer $cached = null;

    public function execute(): void
    {
        $this->cached ??= new Authorizer();
        $this->cached->authorize();
    }
}
