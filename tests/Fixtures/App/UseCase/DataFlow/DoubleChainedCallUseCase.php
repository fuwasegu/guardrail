<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

final class Level1
{
    public function __construct(
        private readonly Level2 $level2,
    ) {}

    public function getLevel2(): Level2
    {
        return $this->level2;
    }
}

final class Level2
{
    public function __construct(
        private readonly Authorizer $authorizer,
    ) {}

    public function getAuthorizer(): Authorizer
    {
        return $this->authorizer;
    }
}

/**
 * Edge case: Double chained method calls
 * $this->level1->getLevel2()->getAuthorizer()->authorize()
 */
final class DoubleChainedCallUseCase
{
    public function __construct(
        private readonly Level1 $level1,
    ) {}

    public function execute(): void
    {
        $this->level1->getLevel2()->getAuthorizer()->authorize();
    }
}
