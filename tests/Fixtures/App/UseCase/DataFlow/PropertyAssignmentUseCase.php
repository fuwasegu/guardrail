<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

/**
 * Data flow: $x = $this->authorizer; $x->authorize()
 * Tests property-to-variable type tracking.
 */
final class PropertyAssignmentUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $x = $this->authorizer;
        $x->authorize();
    }
}
