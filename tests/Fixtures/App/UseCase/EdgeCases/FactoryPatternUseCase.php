<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

final class AuthorizerFactory
{
    public static function create(): Authorizer
    {
        return new Authorizer();
    }
}

/**
 * Edge case: authorize() is called via factory method return
 * [LIMITATION]: Return types from method calls are not tracked
 */
final class FactoryPatternUseCase
{
    public function execute(): void
    {
        $authorizer = AuthorizerFactory::create();
        $authorizer->authorize(); // Type not inferred from factory return type
    }
}
