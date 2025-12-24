<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

/**
 * Edge case: Static property access
 * $x = self::$authorizer; $x->authorize()
 */
final class StaticPropertyUseCase
{
    private static Authorizer $authorizer;

    public function execute(): void
    {
        // Note: Static property would be set elsewhere (e.g., in a bootstrap file)
        $x = self::$authorizer;
        $x->authorize();
    }
}
