<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called via locally instantiated variable
 * [LIMITATION]: Local variable assignment types are not tracked
 */
final class LocalVariableCallUseCase
{
    public function execute(): void
    {
        $authorizer = new Authorizer();
        $authorizer->authorize(); // Type not tracked from assignment
    }
}
