<?php

declare(strict_types=1);

namespace App\UseCase;

use App\Auth\Authorizer;

/**
 * This UseCase calls authorizeOrFail() which should also PASS
 * when using mustCallAnyOf().
 */
class UpdateUserUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(int $userId, array $data): void
    {
        $this->authorizer->authorizeOrFail();

        // Update user logic...
    }
}
