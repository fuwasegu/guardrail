<?php

declare(strict_types=1);

namespace Guardrail\Tests;

use Guardrail\Analysis\Analyzer;
use Guardrail\Config\GuardrailConfig;
use Guardrail\Config\RuleBuilder;
use Guardrail\Config\ScanConfig;
use PHPUnit\Framework\TestCase;

final class PairedCallTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/Fixtures/PairedCall';

        if (!is_dir($this->fixturesPath)) {
            mkdir($this->fixturesPath, permissions: 0o755, recursive: true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up fixture files
        $files = glob($this->fixturesPath . '/*.php');
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->fixturesPath)) {
            rmdir($this->fixturesPath);
        }
    }

    public function testPairedCallViolation(): void
    {
        // Create fixture: beginTransaction is called but commit/rollback is not
        $this->createFixture('TransactionService.php', <<<'PHP'
        <?php
        namespace App\Services;

        use Illuminate\Support\Facades\DB;

        class TransactionService
        {
            public function executeWithoutCommit(): void
            {
                DB::beginTransaction();
                // Oops! Forgot to commit or rollback
            }
        }
        PHP);

        $this->createFixture('DB.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Facades;

        class DB
        {
            public static function beginTransaction(): void {}
            public static function commit(): void {}
            public static function rollback(): void {}
        }
        PHP);

        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule
                    ->entryPoints()
                    ->namespace('App\\Services\\TransactionService')
                    ->method('executeWithoutCommit')
                    ->end();

                $rule->whenCalls(['Illuminate\\Support\\Facades\\DB', 'beginTransaction'])->mustAlsoCall([
                    'Illuminate\\Support\\Facades\\DB',
                    'commit',
                ], ['Illuminate\\Support\\Facades\\DB', 'rollback'])->end();
            })
            ->build();

        $analyzer = new Analyzer($this->fixturesPath);
        $results = $analyzer->analyze($rules, new ScanConfig(paths: ['.'], excludes: []));

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->hasViolations());
        $this->assertCount(1, $results[0]->pairedCallViolations);

        $violation = $results[0]->pairedCallViolations[0];
        $this->assertSame('executeWithoutCommit', $violation->entryPoint->methodName);
        $this->assertStringContainsString('beginTransaction', $violation->getMessage());
    }

    public function testPairedCallSatisfiedWithCommit(): void
    {
        // Create fixture: beginTransaction AND commit are called
        $this->createFixture('TransactionService.php', <<<'PHP'
        <?php
        namespace App\Services;

        use Illuminate\Support\Facades\DB;

        class TransactionService
        {
            public function executeWithCommit(): void
            {
                DB::beginTransaction();
                // ... do work ...
                DB::commit();
            }
        }
        PHP);

        $this->createFixture('DB.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Facades;

        class DB
        {
            public static function beginTransaction(): void {}
            public static function commit(): void {}
            public static function rollback(): void {}
        }
        PHP);

        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule
                    ->entryPoints()
                    ->namespace('App\\Services\\TransactionService')
                    ->method('executeWithCommit')
                    ->end();

                $rule->whenCalls(['Illuminate\\Support\\Facades\\DB', 'beginTransaction'])->mustAlsoCall([
                    'Illuminate\\Support\\Facades\\DB',
                    'commit',
                ], ['Illuminate\\Support\\Facades\\DB', 'rollback'])->end();
            })
            ->build();

        $analyzer = new Analyzer($this->fixturesPath);
        $results = $analyzer->analyze($rules, new ScanConfig(paths: ['.'], excludes: []));

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->hasViolations());
        $this->assertEmpty($results[0]->pairedCallViolations);
    }

    public function testPairedCallSatisfiedWithRollback(): void
    {
        // Create fixture: beginTransaction AND rollback are called
        $this->createFixture('TransactionService.php', <<<'PHP'
        <?php
        namespace App\Services;

        use Illuminate\Support\Facades\DB;

        class TransactionService
        {
            public function executeWithRollback(): void
            {
                DB::beginTransaction();
                // ... something fails ...
                DB::rollback();
            }
        }
        PHP);

        $this->createFixture('DB.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Facades;

        class DB
        {
            public static function beginTransaction(): void {}
            public static function commit(): void {}
            public static function rollback(): void {}
        }
        PHP);

        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule
                    ->entryPoints()
                    ->namespace('App\\Services\\TransactionService')
                    ->method('executeWithRollback')
                    ->end();

                $rule->whenCalls(['Illuminate\\Support\\Facades\\DB', 'beginTransaction'])->mustAlsoCall([
                    'Illuminate\\Support\\Facades\\DB',
                    'commit',
                ], ['Illuminate\\Support\\Facades\\DB', 'rollback'])->end();
            })
            ->build();

        $analyzer = new Analyzer($this->fixturesPath);
        $results = $analyzer->analyze($rules, new ScanConfig(paths: ['.'], excludes: []));

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->hasViolations());
        $this->assertEmpty($results[0]->pairedCallViolations);
    }

    public function testPairedCallNotTriggered(): void
    {
        // Create fixture: beginTransaction is NOT called, so no requirement applies
        $this->createFixture('TransactionService.php', <<<'PHP'
        <?php
        namespace App\Services;

        class TransactionService
        {
            public function executeWithoutTransaction(): void
            {
                // No transaction at all - this is fine
            }
        }
        PHP);

        $this->createFixture('DB.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Facades;

        class DB
        {
            public static function beginTransaction(): void {}
            public static function commit(): void {}
            public static function rollback(): void {}
        }
        PHP);

        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule
                    ->entryPoints()
                    ->namespace('App\\Services\\TransactionService')
                    ->method('executeWithoutTransaction')
                    ->end();

                $rule->whenCalls(['Illuminate\\Support\\Facades\\DB', 'beginTransaction'])->mustAlsoCall([
                    'Illuminate\\Support\\Facades\\DB',
                    'commit',
                ], ['Illuminate\\Support\\Facades\\DB', 'rollback'])->end();
            })
            ->build();

        $analyzer = new Analyzer($this->fixturesPath);
        $results = $analyzer->analyze($rules, new ScanConfig(paths: ['.'], excludes: []));

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->hasViolations());
        $this->assertEmpty($results[0]->pairedCallViolations);
    }

    public function testPairedCallIndirectSatisfaction(): void
    {
        // Create fixture: commit is called indirectly through another method
        $this->createFixture('TransactionService.php', <<<'PHP'
        <?php
        namespace App\Services;

        use Illuminate\Support\Facades\DB;

        class TransactionService
        {
            public function execute(): void
            {
                DB::beginTransaction();
                $this->doWork();
            }

            private function doWork(): void
            {
                // ... work ...
                DB::commit();
            }
        }
        PHP);

        $this->createFixture('DB.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Facades;

        class DB
        {
            public static function beginTransaction(): void {}
            public static function commit(): void {}
            public static function rollback(): void {}
        }
        PHP);

        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\\Services\\TransactionService')->method('execute')->end();

                $rule->whenCalls(['Illuminate\\Support\\Facades\\DB', 'beginTransaction'])->mustAlsoCall([
                    'Illuminate\\Support\\Facades\\DB',
                    'commit',
                ], ['Illuminate\\Support\\Facades\\DB', 'rollback'])->end();
            })
            ->build();

        $analyzer = new Analyzer($this->fixturesPath);
        $results = $analyzer->analyze($rules, new ScanConfig(paths: ['.'], excludes: []));

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->hasViolations());
        $this->assertEmpty($results[0]->pairedCallViolations);
    }

    public function testPairedCallCrossClassSatisfaction(): void
    {
        // Create fixture: commit is called in a different class
        $this->createFixture('TransactionService.php', <<<'PHP'
        <?php
        namespace App\Services;

        use Illuminate\Support\Facades\DB;

        class TransactionService
        {
            public function __construct(
                private TransactionHelper $helper
            ) {}

            public function execute(): void
            {
                DB::beginTransaction();
                $this->helper->commitTransaction();
            }
        }

        class TransactionHelper
        {
            public function commitTransaction(): void
            {
                DB::commit();
            }
        }
        PHP);

        $this->createFixture('DB.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Facades;

        class DB
        {
            public static function beginTransaction(): void {}
            public static function commit(): void {}
            public static function rollback(): void {}
        }
        PHP);

        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\\Services\\TransactionService')->method('execute')->end();

                $rule->whenCalls(['Illuminate\\Support\\Facades\\DB', 'beginTransaction'])->mustAlsoCall([
                    'Illuminate\\Support\\Facades\\DB',
                    'commit',
                ], ['Illuminate\\Support\\Facades\\DB', 'rollback'])->end();
            })
            ->build();

        $analyzer = new Analyzer($this->fixturesPath);
        $results = $analyzer->analyze($rules, new ScanConfig(paths: ['.'], excludes: []));

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->hasViolations());
        $this->assertEmpty($results[0]->pairedCallViolations);
    }

    public function testPairedCallWithCustomMessage(): void
    {
        $this->createFixture('TransactionService.php', <<<'PHP'
        <?php
        namespace App\Services;

        use Illuminate\Support\Facades\DB;

        class TransactionService
        {
            public function executeWithoutCommit(): void
            {
                DB::beginTransaction();
            }
        }
        PHP);

        $this->createFixture('DB.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Facades;

        class DB
        {
            public static function beginTransaction(): void {}
            public static function commit(): void {}
            public static function rollback(): void {}
        }
        PHP);

        $customMessage = 'Transactions must be completed with commit() or rollback()';

        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule) use ($customMessage): void {
                $rule
                    ->entryPoints()
                    ->namespace('App\\Services\\TransactionService')
                    ->method('executeWithoutCommit')
                    ->end();

                $rule
                    ->whenCalls(['Illuminate\\Support\\Facades\\DB', 'beginTransaction'])
                    ->mustAlsoCall(['Illuminate\\Support\\Facades\\DB', 'commit'], [
                        'Illuminate\\Support\\Facades\\DB',
                        'rollback',
                    ])
                    ->message($customMessage)
                    ->end();
            })
            ->build();

        $analyzer = new Analyzer($this->fixturesPath);
        $results = $analyzer->analyze($rules, new ScanConfig(paths: ['.'], excludes: []));

        $this->assertTrue($results[0]->hasViolations());
        $this->assertSame($customMessage, $results[0]->pairedCallViolations[0]->getMessage());
    }

    public function testRuleWithOnlyPairedCalls(): void
    {
        // Rule with ONLY whenCalls (no mustCall) should work
        $this->createFixture('Service.php', <<<'PHP'
        <?php
        namespace App\Services;

        class Service
        {
            public function handle(): void
            {
                // Empty
            }
        }
        PHP);

        $this->createFixture('DB.php', <<<'PHP'
        <?php
        namespace Illuminate\Support\Facades;

        class DB
        {
            public static function beginTransaction(): void {}
            public static function commit(): void {}
        }
        PHP);

        $rules = GuardrailConfig::create()
            ->rule('transaction', static function (RuleBuilder $rule): void {
                $rule->entryPoints()->namespace('App\\Services\\Service')->method('handle')->end();

                $rule->whenCalls(['Illuminate\\Support\\Facades\\DB', 'beginTransaction'])->mustAlsoCall([
                    'Illuminate\\Support\\Facades\\DB',
                    'commit',
                ])->end();
            })
            ->build();

        $analyzer = new Analyzer($this->fixturesPath);
        $results = $analyzer->analyze($rules, new ScanConfig(paths: ['.'], excludes: []));

        $this->assertCount(1, $results);
        // No violations because beginTransaction is not called
        $this->assertFalse($results[0]->hasViolations());
    }

    private function createFixture(string $filename, string $content): void
    {
        file_put_contents($this->fixturesPath . '/' . $filename, $content);
    }
}
