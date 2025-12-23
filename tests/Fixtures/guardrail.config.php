<?php

declare(strict_types=1);

use App\Auth\Authorizer;
use Guardrail\Config\GuardrailConfig;

return GuardrailConfig::create()
    ->rule('authorization')
    ->entryPoints()
    ->namespace('App\\UseCase\\**')
    ->method('execute')
    ->mustCallAnyOf([
        [Authorizer::class, 'authorize'],
        [Authorizer::class, 'authorizeOrFail'],
    ])
    ->atLeastOnce()
    ->message('All UseCases must call Authorizer::authorize() or authorizeOrFail()')
    ->build();
