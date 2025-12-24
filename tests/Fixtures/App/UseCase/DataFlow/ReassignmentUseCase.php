<?php

declare(strict_types=1);

namespace App\UseCase\DataFlow;

use App\Auth\Authorizer;

final class DummyService
{
    public function doNothing(): void {}
}

/**
 * Data flow: $x = new DummyService(); $x = new Authorizer(); $x->authorize()
 * Tests that reassignment updates the type (last assignment wins).
 */
final class ReassignmentUseCase
{
    public function execute(): void
    {
        $x = new DummyService();
        $x = new Authorizer();
        $x->authorize(); // Should be detected as Authorizer::authorize
    }
}
