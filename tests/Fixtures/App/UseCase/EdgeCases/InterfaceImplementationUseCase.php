<?php

declare(strict_types=1);

namespace App\UseCase\EdgeCases;

use App\Auth\Authorizer;

interface UseCaseInterface
{
    public function execute(): void;
}

final class ConcreteUseCase implements UseCaseInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function execute(): void
    {
        $this->authorizer->authorize();
    }
}

/**
 * Edge case: Controller calls UseCase via interface, UseCase calls authorize()
 * Expected: PASS - interface implementation resolution traces through to concrete class
 */
final class InterfaceImplementationUseCase
{
    public function __construct(
        private readonly UseCaseInterface $useCase,
    ) {}

    public function handle(): void
    {
        $this->useCase->execute();
    }
}
