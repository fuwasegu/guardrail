<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

final class AuthorizerProvider
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function provide(): Authorizer
    {
        return $this->authorizer;
    }
}

/**
 * Edge case: Instance method call return type tracking
 * $auth = $this->provider->provide(); $auth->authorize()
 */
final class InstanceMethodReturnUseCase
{
    public function __construct(
        private readonly AuthorizerProvider $provider,
    ) {}

    public function execute(): void
    {
        $auth = $this->provider->provide();
        $auth->authorize();
    }
}
