<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called via locally instantiated variable
 * Supported via data flow analysis (type tracked from new expression)
 */
final class LocalVariableCallUseCase
{
    public function execute(): void
    {
        $authorizer = new Authorizer();
        $authorizer->authorize(); // Type not tracked from assignment
    }
}
