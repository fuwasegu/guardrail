# Guardrail

A static analysis tool for Laravel that verifies API route controllers always call required methods (authorization, logging, etc.).

## Table of Contents

- [Problem](#the-problem)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
  - [Scan Paths](#scan-paths)
  - [Entry Points](#entry-points)
  - [Excluding Routes](#excluding-routes)
  - [Filtering by HTTP Method](#filtering-by-http-method)
  - [Required Calls](#required-calls)
- [CLI](#cli)
- [CI Integration](#ci-integration)
- [How It Works](#how-it-works)
- [Limitations](#limitations)

## The Problem

```php
class OrderController
{
    public function destroy(int $id): JsonResponse
    {
        // Forgot to call $this->authorizer->authorize()!
        return response()->json($this->useCase->execute($id));
    }
}
```

- **Code Review** - Humans miss things
- **Testing** - Hard to write tests for "method must be called"
- **Middleware** - Can't apply to all cases

Guardrail **automatically blocks in CI**.

## Installation

```bash
composer require --dev fuwasegu/guardrail
```

## Quick Start

**1. Create config file** (`guardrail.config.php`)

```php
<?php

use App\Services\Auth\Authorizer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;

return GuardrailConfig::create()
    ->paths(['app'])
    ->rule('authorization', function (RuleBuilder $rule): void {
        $rule->entryPoints()
            ->route('routes/api.php', prefix: '/api')
            ->end();

        $rule->mustCall([Authorizer::class, 'authorize'])
            ->atLeastOnce()
            ->message('All API endpoints must call authorize()');
    });
```

**2. Run**

```bash
./vendor/bin/guardrail
```

**3. Example output**

```
Rule: authorization
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✗ App\Http\Controllers\OrderController::destroy
  All API endpoints must call authorize()

✓ App\Http\Controllers\UserController::index
  via: App\UseCase\ListUsersUseCase::__invoke → Authorizer::authorize

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Summary: 15 total, 14 passed, 1 failed
```

## Configuration

### Scan Paths

Specify directories to scan for building the call graph.

```php
return GuardrailConfig::create()
    ->paths(['app', 'src', 'Modules'])    // Default: ['src', 'app']
    ->exclude(['vendor', 'tests'])         // Default: ['vendor']
    ->rule('...', function (RuleBuilder $rule): void {
        // ...
    });
```

> **Note**: When using `paths()` or `exclude()`, do **NOT** call `->build()` at the end.

### Entry Points

Define the methods to analyze as entry points.

#### From Route Files (Recommended)

```php
$rule->entryPoints()
    ->route('routes/api.php')
    ->route('routes/admin.php')
    ->end();
```

#### With RouteServiceProvider Prefix

Laravel's `RouteServiceProvider` often adds a prefix (like `/api`) to route files. Use the `prefix` parameter to include this:

```php
$rule->entryPoints()
    ->route('routes/api.php', prefix: '/api')  // Routes will be /api/users, /api/orders, etc.
    ->end();
```

#### From Namespace Patterns

```php
$rule->entryPoints()
    ->namespace('App\\Http\\Controllers\\Api\\*')   // Wildcard
    ->namespace('App\\**\\Controllers\\*')          // Recursive
    ->publicMethods()
    ->end();
```

### Excluding Routes

Exclude specific routes from analysis. Patterns match against the full route path (including prefix).

```php
$rule->entryPoints()
    ->route('routes/api.php', prefix: '/api')
    ->excludeRoutes(
        '/api/login',        // Exact match
        '/api/public/*',     // Single segment: /api/public/docs
        '/api/webhooks/**',  // Any depth: /api/webhooks/stripe/payment
    )
    ->end();
```

#### Exclude by Namespace

```php
$rule->entryPoints()
    ->route('routes/api.php')
    ->excluding()
    ->namespace('App\\Http\\Controllers\\HealthController')
    ->end();
```

### Filtering by HTTP Method

Filter routes to only include specific HTTP methods. Useful when authorization rules differ by operation type.

```php
$rule->entryPoints()
    ->route('routes/api.php', prefix: '/api')
    ->httpMethod('POST', 'PUT', 'DELETE')  // Only write operations
    ->end();
```

If `httpMethod()` is not called, all HTTP methods are included by default.

```php
// Combine with route exclusions
$rule->entryPoints()
    ->route('routes/api.php', prefix: '/api')
    ->excludeRoutes('/api/login', '/api/register')
    ->httpMethod('POST', 'PUT', 'PATCH', 'DELETE')
    ->end();
```

### Required Calls

Specify methods that must be called.

```php
// Single method
$rule->mustCall([Authorizer::class, 'authorize'])
    ->atLeastOnce()
    ->message('Authorization required');

// Any of multiple methods
$rule->mustCallAnyOf([
    [Authorizer::class, 'authorize'],
    [Authorizer::class, 'can'],
])
    ->atLeastOnce();
```

### Multiple Rules

```php
return GuardrailConfig::create()
    ->paths(['app', 'Modules'])
    ->rule('authorization', function (RuleBuilder $rule): void {
        $rule->entryPoints()->route('routes/api.php', prefix: '/api')->end();
        $rule->mustCall([Authorizer::class, 'authorize'])->atLeastOnce();
    })
    ->rule('audit-logging', function (RuleBuilder $rule): void {
        $rule->entryPoints()->route('routes/admin.php', prefix: '/admin')->end();
        $rule->mustCall([AuditLogger::class, 'log'])->atLeastOnce();
    });
```

## CLI

```bash
./vendor/bin/guardrail                        # Run with default config
./vendor/bin/guardrail -c path/to/config.php  # Custom config file
./vendor/bin/guardrail -r authorization       # Run specific rule only
./vendor/bin/guardrail -m 2G                  # Set memory limit
./vendor/bin/guardrail -v                     # Verbose output
```

## CI Integration

```yaml
# .github/workflows/guardrail.yml
name: Guardrail
on: [push, pull_request]
jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install
      - run: ./vendor/bin/guardrail
```

## How It Works

### Supported Route Definitions

```php
// ✅ Array syntax
Route::get('/users', [UserController::class, 'index']);

// ✅ Inside groups (middleware, prefix)
Route::prefix('api')->group(function () {
    Route::get('/users', [UserController::class, 'index']);  // → /api/users
});

// ✅ Nested prefixes
Route::prefix('api')->group(function () {
    Route::prefix('v1')->group(function () {
        Route::get('/users', [UserController::class, 'index']);  // → /api/v1/users
    });
});

// ❌ Not yet supported
Route::resource('/posts', PostController::class);
Route::apiResource('/comments', CommentController::class);
```

### Supported Call Patterns

| Pattern | Example |
|---------|---------|
| Direct calls | `$this->authorizer->authorize()` |
| Nested properties | `$this->service->authorizer->authorize()` |
| Method injection | `function handle(Authorizer $auth) { $auth->authorize(); }` |
| Null-safe | `$this->authorizer?->authorize()` |
| Static calls | `Authorizer::authorize()`, `self::`, `static::`, `parent::` |
| Static properties | `$x = self::$auth; $x->authorize()` |
| Invocable | `$useCase($input)` → `__invoke()` |
| Interface resolution | Traces through all implementing classes |
| Closures | `fn() => $this->authorize()` |
| Control flow | `if/else`, `match`, `try/catch`, loops |
| Local variables | `$x = new Auth(); $x->authorize()` |
| Factory returns | `$auth = Factory::create(); $auth->authorize()` |
| Chained calls | `$this->holder->getAuth()->authorize()` |
| Mixed chains | `$this->obj->prop->getAuth()->authorize()` |
| Clone | `$x = clone $this->auth; $x->authorize()` |
| Null coalescing | `$x = $this->auth ?? new Auth(); $x->authorize()` |

## Limitations

Due to the nature of static analysis, the following patterns cannot be detected:

| Pattern | Example | Reason |
|---------|---------|--------|
| Dynamic method | `$obj->$method()` | Resolved at runtime |
| call_user_func | `call_user_func([$obj, 'method'])` | Resolved at runtime |
| Array elements | `$arr[0]->authorize()` | Array type tracking not implemented |
| Null coalescing assign | `$this->x ??= new Auth()` | Compound assignment not tracked |

## Requirements

- PHP 8.1+
- Laravel 9.x / 10.x / 11.x

## License

MIT
