<?php

declare(strict_types=1);

use App\Auth\Authorizer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;

return GuardrailConfig::create()
    ->rule('authorization', function (RuleBuilder $rule): void {
        $rule->entryPoints()
            ->namespace('App\\UseCase\\**')
            ->method('execute');
        $rule->mustCallAnyOf([
            [Authorizer::class, 'authorize'],
            [Authorizer::class, 'authorizeOrFail'],
        ])
            ->atLeastOnce()
            ->message('All UseCases must call Authorizer::authorize() or authorizeOrFail()');
    })
    ->build();
