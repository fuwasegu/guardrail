<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

/**
 * Data flow: $x = new Authorizer(); $y = $x; $y->authorize()
 * Tests variable-to-variable type copy.
 */
final class VariableCopyUseCase
{
    public function execute(): void
    {
        $x = new Authorizer();
        $y = $x;
        $y->authorize();
    }
}
