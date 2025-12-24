<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

abstract class BaseUseCase
{
    public function __construct(
        protected readonly Authorizer $authorizer,
    ) {}

    protected function authorize(): void
    {
        $this->authorizer->authorize();
    }
}

/**
 * Edge case: authorize() is called through parent class method
 * Expected: Need to verify if parent method calls are tracked
 */
final class ParentCallUseCase extends BaseUseCase
{
    public function execute(): void
    {
        $this->authorize();
    }
}
