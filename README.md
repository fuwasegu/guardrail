# Guardrail

A static analysis tool for Laravel that verifies API route controllers always call required methods (authorization, logging, etc.).

## The Problem

```php
// A controller without authorize() slipped into production...
class OrderController
{
    public function destroy(int $id): JsonResponse
    {
        // Forgot to call $this->authorizer->authorize()!
        return response()->json($this->useCase->execute($id));
    }
}
```

Limitations of traditional approaches:
- **Code Review**: Humans miss things
- **Testing**: Hard to write tests for "method must be called"
- **Middleware**: Can't apply to all cases

Guardrail **automatically blocks in CI**.

## Installation

```bash
composer require --dev because-and/guardrail
```

## Quick Start

### 1. Create Configuration File

```php
<?php
// guardrail.config.php

use App\Services\Auth\Authorizer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;

return GuardrailConfig::create()
    ->rule('api-authorization', function (RuleBuilder $rule): void {
        // Target all controllers registered in routes/api.php
        $rule->entryPoints()
            ->route('routes/api.php')
            ->end();

        $rule->mustCall([Authorizer::class, 'authorize'])
            ->atLeastOnce()
            ->message('All API endpoints must call authorize()');
    })
    ->build();
```

### 2. Run

```bash
./vendor/bin/guardrail check
```

### Example Output

```
Guardrail
=========

Rule: api-authorization
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✗ App\Http\Controllers\OrderController::destroy
  /app/Http/Controllers/OrderController.php
  All API endpoints must call authorize()
  No call to App\Services\Auth\Authorizer::authorize found in call chain

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Rules:        1 total, 0 passed, 1 failed
Entry points: 15 total, 14 passed, 1 failed

✗ 1 violation(s) found
```

## Supported Route Definitions

```php
// routes/api.php

// ✅ Supported: Array syntax
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);

// ✅ Supported: Routes inside groups
Route::middleware(['auth:sanctum'])->group(function () {
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
});

// ✅ Supported: Prefixed groups
Route::prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
});

// ❌ Not yet supported (planned)
Route::resource('/posts', PostController::class);
Route::apiResource('/comments', CommentController::class);
```

## Configuration Reference

### Specifying Entry Points

#### From Route Files (Recommended)

```php
$rule->entryPoints()
    ->route('routes/api.php')           // API routes
    ->route('routes/admin.php')         // Multiple files supported
    ->end();
```

#### From Namespace Patterns

```php
$rule->entryPoints()
    ->namespace('App\\Http\\Controllers\\Api\\*')   // Single segment wildcard
    ->namespace('App\\**\\Controllers\\*')          // Recursive wildcard
    ->publicMethods()                               // Public methods only
    ->end();
```

#### Combining Patterns

```php
$rule->entryPoints()
    ->route('routes/api.php')
    ->or()
    ->namespace('App\\Console\\Commands\\*')
    ->method('handle')
    ->excluding()
    ->namespace('App\\Http\\Controllers\\HealthCheckController')
    ->end();
```

### Required Method Calls

```php
// Single method
$rule->mustCall([Authorizer::class, 'authorize']);

// Any of multiple methods
$rule->mustCallAnyOf([
    [Authorizer::class, 'authorize'],
    [Authorizer::class, 'authorizeOrFail'],
    [Authorizer::class, 'can'],
]);
```

### Call Conditions

```php
// Called at least once (default)
$rule->mustCall([...])->atLeastOnce();

// Must be called on all branches (planned)
$rule->mustCall([...])->onAllPaths();
```

## Configuration Examples

### Authorization Check

```php
<?php

use App\Services\Auth\Authorizer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;

return GuardrailConfig::create()
    ->rule('authorization', function (RuleBuilder $rule): void {
        $rule->entryPoints()
            ->route('routes/api.php')
            ->end();

        $rule->mustCallAnyOf([
            [Authorizer::class, 'authorize'],
            [Authorizer::class, 'authorizeOrFail'],
        ])
            ->atLeastOnce()
            ->message('API endpoints require authorization check');
    })
    ->build();
```

### Audit Logging

```php
<?php

use App\Services\Logging\AuditLogger;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;

return GuardrailConfig::create()
    ->rule('audit-logging', function (RuleBuilder $rule): void {
        $rule->entryPoints()
            ->route('routes/admin.php')
            ->end();

        $rule->mustCall([AuditLogger::class, 'log'])
            ->atLeastOnce()
            ->message('Admin operations require audit logging');
    })
    ->build();
```

### Multiple Rules

```php
<?php

use App\Services\Auth\Authorizer;
use App\Services\Logging\AuditLogger;
use App\Services\RateLimiter;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;

return GuardrailConfig::create()
    ->rule('authorization', function (RuleBuilder $rule): void {
        $rule->entryPoints()
            ->route('routes/api.php')
            ->end();

        $rule->mustCall([Authorizer::class, 'authorize'])
            ->atLeastOnce();
    })
    ->rule('audit', function (RuleBuilder $rule): void {
        $rule->entryPoints()
            ->route('routes/admin.php')
            ->end();

        $rule->mustCall([AuditLogger::class, 'log'])
            ->atLeastOnce();
    })
    ->rule('rate-limit', function (RuleBuilder $rule): void {
        $rule->entryPoints()
            ->route('routes/api.php')
            ->end();

        $rule->mustCall([RateLimiter::class, 'check'])
            ->atLeastOnce();
    })
    ->build();
```

## CLI Options

```bash
# Specify configuration file
./vendor/bin/guardrail check --config=path/to/guardrail.config.php

# Specify target directory
./vendor/bin/guardrail check --path=app

# Run specific rule only
./vendor/bin/guardrail check --rule=authorization

# Verbose output
./vendor/bin/guardrail check -v
```

## CI Integration

### GitHub Actions

```yaml
name: Guardrail

on: [push, pull_request]

jobs:
  guardrail:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install
      - run: ./vendor/bin/guardrail check
```

## How It Works

1. Parses `routes/api.php` and extracts `[Controller::class, 'method']` route definitions
2. Builds a call graph from each controller method
3. Verifies reachability to the specified methods
4. Reports unreachable entry points

## Requirements

- PHP 8.1+
- Laravel 9.x / 10.x / 11.x

## License

MIT
