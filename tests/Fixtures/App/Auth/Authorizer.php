<?php

declare(strict_types=1);

namespace App\Auth;

class Authorizer
{
    public function authorize(): void
    {
        // Authorization logic
    }

    public function authorizeOrFail(): void
    {
        // Authorization logic with exception
    }
}
