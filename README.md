# Guardrail

Laravel 向け静的解析ツール。API ルートに登録された Controller が、必ず特定のメソッド（認可、ログ出力など）を呼び出しているかを検証します。

## 課題

```php
// 認可チェックを忘れた Controller が本番にデプロイされてしまった...
class OrderController
{
    public function destroy(int $id): JsonResponse
    {
        // $this->authorizer->authorize() を呼び忘れ！
        return response()->json($this->useCase->execute($id));
    }
}
```

従来のアプローチの限界：
- **コードレビュー**: 人間は見落とす
- **テスト**: 「メソッドが呼ばれること」のテストは書きにくい
- **Middleware**: 全ケースには適用できない

Guardrail は **CI で自動的にブロック**します。

## インストール

```bash
composer require --dev because-and/guardrail
```

## クイックスタート

### 1. 設定ファイルを作成

```php
<?php
// guardrail.config.php

use App\Services\Auth\Authorizer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;

return GuardrailConfig::create()
    ->rule('api-authorization', function (RuleBuilder $rule): void {
        // routes/api.php に登録された全 Controller を対象
        $rule->entryPoints()
            ->route('routes/api.php')
            ->end();

        $rule->mustCall([Authorizer::class, 'authorize'])
            ->atLeastOnce()
            ->message('全ての API エンドポイントで authorize() を呼び出してください');
    })
    ->build();
```

### 2. 実行

```bash
./vendor/bin/guardrail check
```

### 出力例

```
Guardrail
=========

Rule: api-authorization
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✗ App\Http\Controllers\OrderController::destroy
  /app/Http/Controllers/OrderController.php
  全ての API エンドポイントで authorize() を呼び出してください
  No call to App\Services\Auth\Authorizer::authorize found in call chain

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Rules:        1 total, 0 passed, 1 failed
Entry points: 15 total, 14 passed, 1 failed

✗ 1 violation(s) found
```

## 対応するルート定義

```php
// routes/api.php

// ✅ 対応: 配列記法
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);

// ✅ 対応: グループ内のルート
Route::middleware(['auth:sanctum'])->group(function () {
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
});

// ✅ 対応: prefix 付きグループ
Route::prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
});

// ❌ 未対応（将来対応予定）
Route::resource('/posts', PostController::class);
Route::apiResource('/comments', CommentController::class);
```

## 設定リファレンス

### エントリーポイントの指定

#### ルートファイルから（推奨）

```php
$rule->entryPoints()
    ->route('routes/api.php')           // API ルート
    ->route('routes/admin.php')         // 複数ファイル指定可能
    ->end();
```

#### 名前空間パターンから

```php
$rule->entryPoints()
    ->namespace('App\\Http\\Controllers\\Api\\*')   // 単一セグメント
    ->namespace('App\\**\\Controllers\\*')          // 再帰的ワイルドカード
    ->publicMethods()                               // public メソッドのみ
    ->end();
```

#### 組み合わせ

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

### 必須メソッド呼び出し

```php
// 単一メソッド
$rule->mustCall([Authorizer::class, 'authorize']);

// 複数メソッドのいずれか
$rule->mustCallAnyOf([
    [Authorizer::class, 'authorize'],
    [Authorizer::class, 'authorizeOrFail'],
    [Authorizer::class, 'can'],
]);
```

### 呼び出し条件

```php
// 1回以上呼び出し（デフォルト）
$rule->mustCall([...])->atLeastOnce();

// 全分岐で呼び出し（将来対応予定）
$rule->mustCall([...])->onAllPaths();
```

## 設定例

### 認可チェック

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
            ->message('API エンドポイントには認可チェックが必要です');
    })
    ->build();
```

### 監査ログ

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
            ->message('管理者操作には監査ログが必要です');
    })
    ->build();
```

### 複数ルール

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

## CLI オプション

```bash
# 設定ファイルを指定
./vendor/bin/guardrail check --config=path/to/guardrail.config.php

# 対象ディレクトリを指定
./vendor/bin/guardrail check --path=app

# 特定のルールのみ実行
./vendor/bin/guardrail check --rule=authorization

# 詳細出力
./vendor/bin/guardrail check -v
```

## CI 連携

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

## 動作原理

1. `routes/api.php` をパースし、`[Controller::class, 'method']` 形式のルート定義を抽出
2. 各 Controller メソッドから呼び出しグラフ（Call Graph）を構築
3. 指定されたメソッドへの到達可能性を検証
4. 到達不可能なエントリーポイントを報告

## 要件

- PHP 8.1+
- Laravel 9.x / 10.x / 11.x

## ライセンス

MIT
