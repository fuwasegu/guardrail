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
composer require --dev fuwasegu/guardrail
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

# Set memory limit (like PHPStan)
./vendor/bin/guardrail check --memory-limit=1G
./vendor/bin/guardrail check -m 2G
./vendor/bin/guardrail check --memory-limit=-1  # Unlimited

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

## Supported Patterns

The following call patterns are detected:

### Method Calls
- ✅ Direct method calls: `$this->authorizer->authorize()`
- ✅ Property method calls: `$this->service->authorizer->authorize()`
- ✅ Method injection: `public function handle(Authorizer $auth) { $auth->authorize(); }`
- ✅ Null-safe operator: `$this->authorizer?->authorize()`

### Static Calls
- ✅ Class static calls: `Authorizer::authorize()`
- ✅ `self::method()`: Resolves to current class
- ✅ `static::method()`: Resolves to current class (including inherited methods)
- ✅ `parent::method()`: Resolves to parent class

### Invocable Patterns
- ✅ Variable invocable: `$useCase($input)` → calls `__invoke()`
- ✅ Property invocable: `($this->useCase)($input)` → calls `__invoke()`
- ✅ First-class callable creation: `$callable = $this->authorizer->authorize(...)`

### Closure & Arrow Functions
- ✅ Closure body: `$closure = function() { $this->authorizer->authorize(); }`
- ✅ Arrow function: `$fn = fn() => $this->authorizer->authorize()`

### Type Resolution
- ✅ Interface type hints: `AuthorizerInterface $authorizer`
- ✅ **Interface implementation resolution**: When calling a method on an interface-typed property, all implementing classes are analyzed
- ✅ Trait method calls: calls through trait methods
- ✅ Parent class methods: calls through inherited methods

### Control Flow
- ✅ Conditional: `if/else` branches
- ✅ Loops: `foreach/for/while` loops
- ✅ Try-Catch: `try/catch/finally` blocks
- ✅ Match expression: `match ($x) { 'a' => $this->authorize(), ... }`
- ✅ Ternary: `$condition ? $this->authorize() : null`
- ✅ Null coalescing: `$this->auth?->authorize() ?? $this->fallback()`

### Interface Implementation Resolution Example

```php
// When your Controller calls a UseCase via interface:
class OrderController
{
    public function __construct(
        private readonly CreateOrderUseCaseInterface $useCase  // Interface
    ) {}

    public function store(): Response
    {
        $this->useCase->execute();  // Guardrail traces through to all implementing classes
    }
}

// Guardrail will find that CreateOrderUseCase::execute() calls authorize()
class CreateOrderUseCase implements CreateOrderUseCaseInterface
{
    public function execute(): void
    {
        $this->authorizer->authorize();  // This is detected!
    }
}
```

## Known Limitations

As a static analysis tool, certain patterns cannot be detected:

| Pattern | Example | Reason |
|---------|---------|--------|
| Dynamic method calls | `$method = 'authorize'; $this->authorizer->$method()` | Method name resolved at runtime |
| `call_user_func` | `call_user_func([$this->authorizer, 'authorize'])` | Target resolved at runtime |
| Array callback execution | `$callable = [$obj, 'method']; $callable()` | Array callback type not tracked |
| Array callback in functions | `array_map([$obj, 'method'], $items)` | Callback passed to function |
| Closure passed as argument | `$this->run(function() { ... })` | Closure body not linked to caller |
| Local variable types | `$auth = $this->authorizer; $auth->authorize()` | Type inference not implemented |
| Factory pattern | `$auth = $factory->create(); $auth->authorize()` | Return type tracking not implemented |
| Chained calls | `$this->holder->getAuthorizer()->authorize()` | Return type tracking not implemented |
| Variable class names | `$class::method()` | Class name resolved at runtime |
| Reflection calls | `$method->invoke($obj)` | Reflection resolves at runtime |

## Requirements

- PHP 8.1+
- Laravel 9.x / 10.x / 11.x

## License

MIT
