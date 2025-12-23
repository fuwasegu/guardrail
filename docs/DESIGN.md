# Guardrail - PHP Call Chain Assertion Tool

## Overview

Guardrail は、PHP コードの実行経路において「特定のメソッドが必ず呼ばれること」を静的解析で検証するツール。

### 解決する問題

```php
// ❌ authorize() を呼び忘れたルートが本番に...
class OrderController {
    public function destroy($id) {
        // $this->authorizer->authorize() を忘れた！
        $this->useCase->execute($id);
    }
}
```

従来のアプローチの限界：
- **コードレビュー**: 人間は見落とす
- **テスト**: 「呼ばれること」のテストは書きにくい
- **Middleware**: すべてのケースに適用できない

Guardrail は **CI で自動的にブロック** する。

---

## Core Concepts

### 1. Entry Point（起点）

検証を開始する場所。

```php
->entryPoints()
    ->routes('api/*')                          // Route パターン
    ->namespace('App\\UseCase\\*')             // Namespace
    ->implements(UseCaseInterface::class)      // Interface
    ->attribute(RequiresAuth::class)           // Attribute
```

### 2. Required Call（必須呼び出し）

経路のどこかで呼ばれるべきメソッド。

```php
->mustCall([Authorizer::class, 'authorize'])
```

### 3. Path Condition（経路条件）

どのように呼ばれるべきか。

```php
->onAllPaths()     // すべての分岐で呼ばれる
->atLeastOnce()    // どこかで1回呼ばれればOK
->before([...])    // 特定のメソッドより前に
->after([...])     // 特定のメソッドより後に
```

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        guardrail check                          │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Configuration Loader                         │
│                   guardrail.config.php                          │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                       Collector Layer                           │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│  │RouteCollector│  │ClassCollector│ │NamespaceCollector│  │
│  └─────────────┘  └─────────────┘  └─────────────────────────┘  │
│                                                                 │
│  Entry Point を収集                                              │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Analysis Layer                             │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                   AST Parser (php-parser)                │   │
│  └──────────────────────────────────────────────────────────┘   │
│                              │                                  │
│                              ▼                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                  Call Graph Builder                      │   │
│  │  - Method calls: $this->foo(), $obj->bar()               │   │
│  │  - Static calls: Foo::bar()                              │   │
│  │  - Constructor tracking for DI                           │   │
│  └──────────────────────────────────────────────────────────┘   │
│                              │                                  │
│                              ▼                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │               Control Flow Graph (CFG)                   │   │
│  │  - if/else branches                                      │   │
│  │  - try/catch/finally                                     │   │
│  │  - early return                                          │   │
│  │  - loops                                                 │   │
│  └──────────────────────────────────────────────────────────┘   │
│                              │                                  │
│                              ▼                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                  Path Analyzer                           │   │
│  │  - Enumerate all possible paths                          │   │
│  │  - Check if required call exists on each path            │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                       Reporter Layer                            │
│  ┌───────────┐  ┌───────────┐  ┌─────────────────────────────┐  │
│  │  Console  │  │   JSON    │  │   GitHub Actions            │  │
│  └───────────┘  └───────────┘  └─────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Configuration DSL

### 基本構造

```php
<?php
// guardrail.config.php

use Guardrail\Config\GuardrailConfig;
use App\Auth\Authorizer;

return GuardrailConfig::create()
    ->rule('rule-name')
        ->entryPoints()
            // ... collectors
        ->mustCall([ClassName::class, 'methodName'])
        // ... conditions
    ->build();
```

### Entry Point Collectors

#### Route Collector (Laravel)

```php
->entryPoints()
    ->routes('api/*')                    // パスパターン
    ->routes('api/admin/*')
    ->routeName('admin.*')               // ルート名
    ->routeMethod('POST', 'PUT', 'DELETE')  // HTTP メソッド
    ->routeMiddleware('auth')            // Middleware
```

#### Class Collector

```php
->entryPoints()
    ->namespace('App\\UseCase\\*')       // Namespace (glob)
    ->namespace('App\\**\\Admin\\*')     // 再帰 glob
    ->implements(UseCaseInterface::class)
    ->extends(BaseUseCase::class)
    ->attribute(RequiresAuth::class)
```

#### Directory Collector

```php
->entryPoints()
    ->directory('src/UseCase')
    ->directory('src/Http/Controllers')
```

#### Method Filter

```php
->entryPoints()
    ->namespace('App\\UseCase\\*')
    ->method('execute')                  // 特定メソッドのみ
    ->publicMethods()                    // public メソッドのみ
```

#### Composite

```php
->entryPoints()
    ->namespace('App\\UseCase\\*')
    ->or()
    ->namespace('App\\Service\\*')
    ->excluding()
    ->namespace('App\\Service\\Internal\\*')
```

### Required Calls

```php
// 単一メソッド
->mustCall([Authorizer::class, 'authorize'])

// 複数のうちどれか
->mustCallAnyOf([
    [Authorizer::class, 'authorize'],
    [Authorizer::class, 'authorizeOrFail'],
])

// 順序指定
->mustCallInOrder([
    [DB::class, 'beginTransaction'],
    [DB::class, 'commit'],
])
```

### Path Conditions

```php
// すべての分岐で呼ばれる必要がある
->onAllPaths()

// どこかで1回呼ばれればOK
->atLeastOnce()

// 特定呼び出しの前に
->before([Repository::class, 'save'])

// 特定呼び出しの後に
->after([Validator::class, 'validate'])
```

### Exclusions

```php
->rule('authorization')
    ->entryPoints()
        ->routes('api/*')
    ->excluding()
        ->routes('api/health', 'api/public/*')
        ->attribute(SkipAuth::class)
    ->mustCall([Authorizer::class, 'authorize'])
```

---

## Example Configurations

### 1. 認可チェック

```php
<?php
use Guardrail\Config\GuardrailConfig;
use App\Auth\Authorizer;

return GuardrailConfig::create()
    ->rule('authorization')
        ->entryPoints()
            ->routes('api/*')
            ->excluding()
                ->routes('api/health', 'api/auth/*', 'api/public/*')
        ->mustCall([Authorizer::class, 'authorize'])
        ->onAllPaths()
        ->message('All API endpoints must call authorize()')
    ->build();
```

### 2. 監査ログ

```php
<?php
use Guardrail\Config\GuardrailConfig;
use App\Logging\AuditLogger;
use App\UseCase\Admin\AdminUseCaseInterface;

return GuardrailConfig::create()
    ->rule('audit-logging')
        ->entryPoints()
            ->implements(AdminUseCaseInterface::class)
        ->mustCall([AuditLogger::class, 'log'])
        ->atLeastOnce()
        ->message('Admin operations must be audit logged')
    ->build();
```

### 3. トランザクション整合性

```php
<?php
use Guardrail\Config\GuardrailConfig;
use Illuminate\Support\Facades\DB;
use App\Attributes\Transactional;

return GuardrailConfig::create()
    ->rule('transaction')
        ->entryPoints()
            ->attribute(Transactional::class)
        ->mustCallInOrder([
            [DB::class, 'beginTransaction'],
            [DB::class, 'commit'],
        ])
        ->withCatch([DB::class, 'rollBack'])
        ->message('Transactional methods must properly manage transactions')
    ->build();
```

### 4. 入力バリデーション

```php
<?php
use Guardrail\Config\GuardrailConfig;
use Illuminate\Http\Request;

return GuardrailConfig::create()
    ->rule('validation')
        ->entryPoints()
            ->namespace('App\\Http\\Controllers\\*')
            ->method('store', 'update')
        ->mustCallAnyOf([
            [Request::class, 'validate'],
            [Request::class, 'validated'],
            [\Illuminate\Support\Facades\Validator::class, 'validate'],
        ])
        ->before([
            ['*Repository', 'save'],
            ['*Repository', 'create'],
        ])
        ->message('Input must be validated before persistence')
    ->build();
```

### 5. 複合ルール

```php
<?php
use Guardrail\Config\GuardrailConfig;

return GuardrailConfig::create()

    ->rule('authorization')
        ->entryPoints()->routes('api/*')
        ->excluding()->routes('api/public/*')
        ->mustCall([Authorizer::class, 'authorize'])
        ->onAllPaths()

    ->rule('audit')
        ->entryPoints()->namespace('App\\UseCase\\Admin\\*')
        ->mustCall([AuditLogger::class, 'log'])
        ->atLeastOnce()

    ->rule('rate-limit')
        ->entryPoints()->routes('api/external/*')
        ->mustCall([RateLimiter::class, 'check'])
        ->before([HttpClient::class, 'request'])

    ->build();
```

---

## CLI Interface

### Commands

```bash
# 全ルールをチェック
./vendor/bin/guardrail check

# 特定ルールのみ
./vendor/bin/guardrail check --rule=authorization

# 特定ディレクトリのみ
./vendor/bin/guardrail check --path=src/UseCase

# 設定ファイル指定
./vendor/bin/guardrail check --config=custom.config.php

# 出力フォーマット
./vendor/bin/guardrail check --format=json
./vendor/bin/guardrail check --format=github  # GitHub Actions 用

# 詳細出力
./vendor/bin/guardrail check -v
./vendor/bin/guardrail check -vv  # コールグラフも表示
```

### Output Example

```
$ ./vendor/bin/guardrail check

Guardrail v0.1.0

Loading configuration from guardrail.config.php
Analyzing 47 entry points across 3 rules...

Rule: authorization
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✗ POST /api/orders
  └─► OrderController::store (app/Http/Controllers/OrderController.php:34)
      └─► CreateOrderUseCase::execute
          ├─► [✓] Authorizer::authorize
          └─► [if: $request->express]
              └─► CreateOrderUseCase::executeExpress
                  └─► [✗] No call to Authorizer::authorize

  ⚠ Conditional branch at line 45 may skip authorization

✓ DELETE /api/orders/{id} .............. OK
✓ GET /api/users ....................... OK
✓ POST /api/users ...................... OK

Rule: audit-logging
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✓ All 12 entry points passed

Rule: rate-limit
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✓ All 5 entry points passed

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Rules:        3 total, 2 passed, 1 failed
Entry points: 47 total, 46 passed, 1 failed
Time:         1.23s

✗ 1 violation found
```

---

## Implementation Phases

### Phase 1: Foundation (MVP)

**Goal**: 最小限動くものを作る

- [ ] Project setup (Composer, PSR-4)
- [ ] Config DSL の基本実装
- [ ] Namespace Collector
- [ ] AST Parser (php-parser)
- [ ] 単純な Call Graph (直接呼び出しのみ)
- [ ] `atLeastOnce()` チェック
- [ ] Console Reporter

```php
// Phase 1 で動く設定
return GuardrailConfig::create()
    ->rule('authorization')
        ->entryPoints()
            ->namespace('App\\UseCase\\*')
        ->mustCall([Authorizer::class, 'authorize'])
        ->atLeastOnce()
    ->build();
```

### Phase 2: Laravel Integration

**Goal**: Route 起点の解析

- [ ] Laravel Route Parser
- [ ] Route Collector
- [ ] Controller → UseCase の追跡
- [ ] `routes()` DSL

### Phase 3: Type Resolution

**Goal**: DI された依存を追跡

- [ ] Constructor injection の解析
- [ ] Property type の解析
- [ ] Laravel Container の解決 (基本)

```php
class OrderController {
    public function __construct(
        private CreateOrderUseCase $useCase  // ← この型を追跡
    ) {}
}
```

### Phase 4: Control Flow

**Goal**: 分岐を考慮した解析

- [ ] Control Flow Graph (CFG) 構築
- [ ] if/else, try/catch, early return
- [ ] `onAllPaths()` チェック
- [ ] 分岐条件の表示

### Phase 5: Advanced Features

**Goal**: 高度な機能

- [ ] `before()`, `after()` 順序チェック
- [ ] `mustCallInOrder()`
- [ ] Wildcard method matching (`['*Repository', 'save']`)
- [ ] GitHub Actions Reporter
- [ ] Cache for incremental analysis

### Phase 6: SSA (Optional)

**Goal**: 完全なデータフロー解析

- [ ] SSA 変換
- [ ] Phi node 処理
- [ ] 間接呼び出しの追跡

---

## Technical Details

### Dependencies

```json
{
    "require": {
        "php": "^8.1",
        "nikic/php-parser": "^5.0",
        "symfony/console": "^6.0|^7.0",
        "symfony/finder": "^6.0|^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "phpstan/phpstan": "^1.10"
    }
}
```

### Directory Structure

```
guardrail/
├── src/
│   ├── Config/
│   │   ├── GuardrailConfig.php
│   │   ├── Rule.php
│   │   ├── RuleBuilder.php
│   │   └── MethodReference.php
│   │
│   ├── Collector/
│   │   ├── CollectorInterface.php
│   │   ├── EntryPointCollector.php
│   │   ├── NamespaceCollector.php
│   │   ├── ClassCollector.php
│   │   ├── DirectoryCollector.php
│   │   └── Laravel/
│   │       └── RouteCollector.php
│   │
│   ├── Analysis/
│   │   ├── Analyzer.php
│   │   ├── CallGraph/
│   │   │   ├── CallGraphBuilder.php
│   │   │   ├── CallGraph.php
│   │   │   └── Node.php
│   │   ├── ControlFlow/
│   │   │   ├── ControlFlowGraphBuilder.php
│   │   │   ├── ControlFlowGraph.php
│   │   │   └── BasicBlock.php
│   │   ├── PathAnalyzer.php
│   │   └── TypeResolver.php
│   │
│   ├── Reporter/
│   │   ├── ReporterInterface.php
│   │   ├── ConsoleReporter.php
│   │   ├── JsonReporter.php
│   │   └── GitHubActionsReporter.php
│   │
│   └── Command/
│       └── CheckCommand.php
│
├── bin/
│   └── guardrail
│
├── tests/
│   ├── Config/
│   ├── Collector/
│   ├── Analysis/
│   └── Fixtures/
│
├── guardrail.config.php.dist
├── composer.json
├── phpunit.xml
└── README.md
```

---

## Future Ideas

- **MCP Server**: 同じ解析エンジンを MCP でラップして対話的に使う
- **IDE Plugin**: PHPStorm / VS Code で警告表示
- **Auto-fix**: 一部のケースで自動修正
- **Custom Collectors**: ユーザー定義の Collector
- **Baseline**: 既存の違反を無視して新規のみ検出
