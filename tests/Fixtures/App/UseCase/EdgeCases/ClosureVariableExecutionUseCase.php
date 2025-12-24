<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: closure assigned to variable and executed via $var()
 * The closure BODY is traversed, and `$callback()` matches our invocable pattern
 * BUT: We can only detect if $callback has a type hint
 * Expected: FAIL - closure variable type is not tracked (Closure class)
 */
final class ClosureVariableExecutionUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $callback = function () {
            $this->authorizer->authorize();
        };
        // $callback() -> analyzeInvocableCall looks for type in currentMethodVariables
        // But closure doesn't have a type hint, so type is unknown
        $callback();
    }
}

/**
 * Edge case: closure passed as typed parameter
 * Expected: Still FAIL - Closure type doesn't have authorize method
 */
final class ClosureTypedParameterUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $this->runWithCallback(function () {
            $this->authorizer->authorize();
        });
    }

    private function runWithCallback(\Closure $callback): void
    {
        $callback();
    }
}
