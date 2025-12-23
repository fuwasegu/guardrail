# Guardrail

PHP コードの実行経路において「特定のメソッドが必ず呼ばれること」を静的解析で検証するツール。

## 解決する問題

```php
// authorize() を呼び忘れたルートが本番に...
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

## インストール

```bash
composer require --dev guardrail/guardrail
```

## 使い方

### 1. 設定ファイルを作成

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

### 2. 実行

```bash
./vendor/bin/guardrail check
```

### 出力例

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

## CLI オプション

```bash
# 設定ファイル指定
./vendor/bin/guardrail check --config=path/to/guardrail.config.php

# 解析対象ディレクトリ指定
./vendor/bin/guardrail check --path=src/UseCase

# 特定ルールのみ実行
./vendor/bin/guardrail check --rule=authorization

# 詳細出力
./vendor/bin/guardrail check -v
```

## 設定 DSL

### Entry Point Collectors

```php
// Namespace パターン
->entryPoints()
    ->namespace('App\\UseCase\\*')       // 単一セグメント
    ->namespace('App\\**\\Admin\\*')     // 再帰

// メソッドフィルター
->entryPoints()
    ->namespace('App\\UseCase\\*')
    ->method('execute')                  // 特定メソッドのみ
    ->publicMethods()                    // public メソッドのみ

// 複合
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
```

### Path Conditions

```php
// どこかで1回呼ばれればOK（デフォルト）
->atLeastOnce()

// すべての分岐で呼ばれる必要がある（将来実装予定）
->onAllPaths()
```

## 設定例

### 認可チェック

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

### 監査ログ

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

### 複合ルール

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
          php-version: '8.2'
      - run: composer install
      - run: ./vendor/bin/guardrail check
```

## 要件

- PHP 8.1+

## ライセンス

MIT
