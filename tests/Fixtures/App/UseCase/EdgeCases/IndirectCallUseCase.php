<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

/**
 * Edge case: authorize() is called indirectly through another service
 * Expected: PASS (authorize is called via $this->helper->doWithAuth())
 */
final class IndirectCallUseCase
{
    public function __construct(
        private readonly AuthHelper $helper,
    ) {}

    public function execute(): void
    {
        // authorize() is called inside helper->doWithAuth()
        $this->helper->doWithAuth();
    }
}

final class AuthHelper
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function doWithAuth(): void
    {
        $this->authorizer->authorize();
    }
}
