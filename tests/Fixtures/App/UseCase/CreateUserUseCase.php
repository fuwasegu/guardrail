<?php

declare(strict_types=1);

namespace App\UseCase;

use App\Auth\Authorizer;

/**
 * This UseCase correctly calls authorize() - should PASS.
 */
class CreateUserUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(array $data): void
    {
        $this->authorizer->authorize();

        // Create user logic...
    }
}
