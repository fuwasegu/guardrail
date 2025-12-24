<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() via array callback syntax [$obj, 'method']
 * Expected: FAIL - array callback execution is NOT detected
 * This is a known limitation
 */
final class ArrayCallbackExecuteUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $callable = [$this->authorizer, 'authorize'];
        $callable();
    }
}

/**
 * Edge case: authorize() via array_map with callback
 * Expected: FAIL - array callback in array_map is NOT detected
 */
final class ArrayMapCallbackUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $items = ['a', 'b', 'c'];
        array_map([$this->authorizer, 'authorize'], $items);
    }
}
