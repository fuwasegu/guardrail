# Guardrail

A static analysis tool that verifies specific methods are always called within PHP code execution paths.

## The Problem

```php
// A route without authorize() slipped into production...
class OrderController {
    public function destroy($id) {
        // Forgot to call $this->authorizer->authorize()!
        $this->useCase->execute($id);
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

## Usage

### 1. Create Configuration File

```php
<?php
// guardrail.config.php

use App\Auth\Authorizer;
use Guardrail\Config\GuardrailConfig;

return GuardrailConfig::create()
    ->rule('authorization')
        ->entryPoints()
            ->namespace('App\\UseCase\\*')
            ->method('execute')
        ->mustCallAnyOf([
            [Authorizer::class, 'authorize'],
            [Authorizer::class, 'authorizeOrFail'],
        ])
        ->atLeastOnce()
        ->message('All UseCases must call authorize()')
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

Rule: authorization
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✗ App\UseCase\DeleteUserUseCase::execute
  /path/to/DeleteUserUseCase.php
  All UseCases must call authorize()
  No call to App\Auth\Authorizer::authorize found in call chain

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Rules:        1 total, 0 passed, 1 failed
Entry points: 3 total, 2 passed, 1 failed

✗ 1 violation(s) found
```

## CLI Options

```bash
# Specify configuration file
./vendor/bin/guardrail check --config=path/to/guardrail.config.php

# Specify target directory
./vendor/bin/guardrail check --path=src/UseCase

# Run specific rule only
./vendor/bin/guardrail check --rule=authorization

# Verbose output
./vendor/bin/guardrail check -v
```

## Configuration DSL

### Entry Point Collectors

```php
// Namespace patterns
->entryPoints()
    ->namespace('App\\UseCase\\*')       // Single segment wildcard
    ->namespace('App\\**\\Admin\\*')     // Recursive wildcard

// Method filters
->entryPoints()
    ->namespace('App\\UseCase\\*')
    ->method('execute')                  // Specific methods only
    ->publicMethods()                    // Public methods only

// Combining patterns
->entryPoints()
    ->namespace('App\\UseCase\\*')
    ->or()
    ->namespace('App\\Service\\*')
    ->excluding()
    ->namespace('App\\Service\\Internal\\*')
```

### Required Calls

```php
// Single method
->mustCall([Authorizer::class, 'authorize'])

// Any of multiple methods
->mustCallAnyOf([
    [Authorizer::class, 'authorize'],
    [Authorizer::class, 'authorizeOrFail'],
])
```

### Path Conditions

```php
// Called at least once anywhere (default)
->atLeastOnce()

// Must be called on all branches (planned for future)
->onAllPaths()
```

## Configuration Examples

### Authorization Check

```php
<?php
use App\Auth\Authorizer;
use Guardrail\Config\GuardrailConfig;

return GuardrailConfig::create()
    ->rule('authorization')
        ->entryPoints()
            ->namespace('App\\UseCase\\*')
            ->method('execute')
        ->mustCall([Authorizer::class, 'authorize'])
        ->atLeastOnce()
        ->message('All UseCases must call authorize()')
    ->build();
```

### Audit Logging

```php
<?php
use App\Logging\AuditLogger;
use Guardrail\Config\GuardrailConfig;

return GuardrailConfig::create()
    ->rule('audit-logging')
        ->entryPoints()
            ->namespace('App\\UseCase\\Admin\\*')
            ->method('execute')
        ->mustCall([AuditLogger::class, 'log'])
        ->atLeastOnce()
        ->message('Admin operations must be audit logged')
    ->build();
```

### Multiple Rules

```php
<?php
use App\Auth\Authorizer;
use App\Logging\AuditLogger;
use Guardrail\Config\GuardrailConfig;

return GuardrailConfig::create()

    ->rule('authorization')
        ->entryPoints()
            ->namespace('App\\UseCase\\*')
            ->method('execute')
        ->mustCall([Authorizer::class, 'authorize'])
        ->atLeastOnce()

    ->rule('audit')
        ->entryPoints()
            ->namespace('App\\UseCase\\Admin\\*')
            ->method('execute')
        ->mustCall([AuditLogger::class, 'log'])
        ->atLeastOnce()

    ->build();
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
          php-version: '8.2'
      - run: composer install
      - run: ./vendor/bin/guardrail check
```

## Requirements

- PHP 8.1+

## License

MIT
