<?php

declare(strict_types=1);

namespace App\UseCase;

use App\Auth\Authorizer;

/**
 * This UseCase DOES NOT call authorize() - should FAIL.
 */
class DeleteUserUseCase
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(int $userId): void
    {
        // OOPS! Forgot to call authorize()!
        // Delete user logic...
    }
}
