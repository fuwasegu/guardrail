<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

final class StaticAuthorizer
{
    public static function authorize(): void
    {
        // Static authorization
    }
}

/**
 * Edge case: authorize() is called statically
 * Expected: Should pass - static calls are supported
 */
final class StaticCallUseCase
{
    public function execute(): void
    {
        StaticAuthorizer::authorize();
    }
}
