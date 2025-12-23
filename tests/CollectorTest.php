<?php

declare(strict_types=1);

namespace Guardrail\Tests;

use Guardrail\Collector\CompositeCollector;
use Guardrail\Collector\EntryPoint;
use Guardrail\Collector\ExcludingCollector;
use Guardrail\Collector\NamespaceCollector;
use PHPUnit\Framework\TestCase;

final class CollectorTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = __DIR__ . '/Fixtures/App';
    }

    // ========================================
    // NamespaceCollector Tests
    // ========================================

    public function testNamespaceCollectorWithExactMatch(): void
    {
        $collector = (new NamespaceCollector())->namespace('App\UseCase\CreateUserUseCase');
        $entryPoints = iterator_to_array($collector->collect($this->basePath), preserve_keys: false);

        $this->assertNotEmpty($entryPoints);
        $this->assertContainsOnlyInstancesOf(EntryPoint::class, $entryPoints);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);
        $this->assertContains('App\UseCase\CreateUserUseCase::execute', $identifiers);
    }

    public function testNamespaceCollectorWithWildcard(): void
    {
        $collector = (new NamespaceCollector())->namespace('App\UseCase\*UseCase');
        $entryPoints = iterator_to_array($collector->collect($this->basePath), preserve_keys: false);

        $this->assertNotEmpty($entryPoints);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);
        $this->assertContains('App\UseCase\CreateUserUseCase::execute', $identifiers);
        $this->assertContains('App\UseCase\DeleteUserUseCase::execute', $identifiers);
    }

    public function testNamespaceCollectorWithDoubleWildcard(): void
    {
        $collector = (new NamespaceCollector())->namespace('App\UseCase\**');
        $entryPoints = iterator_to_array($collector->collect($this->basePath), preserve_keys: false);

        $this->assertNotEmpty($entryPoints);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);

        // Should include nested namespaces
        $this->assertContains('App\UseCase\CreateUserUseCase::execute', $identifiers);
        $this->assertTrue(
            count(array_filter($identifiers, static fn($id) => str_contains($id, 'EdgeCases'))) > 0,
            'Should include EdgeCases namespace',
        );
    }

    public function testNamespaceCollectorWithMethodFilter(): void
    {
        $collector = (new NamespaceCollector())
            ->namespace('App\UseCase\*')
            ->method('execute');
        $entryPoints = iterator_to_array($collector->collect($this->basePath), preserve_keys: false);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);

        foreach ($identifiers as $id) {
            $this->assertStringEndsWith('::execute', $id);
        }
    }

    public function testNamespaceCollectorWithMultipleMethodFilters(): void
    {
        $collector = (new NamespaceCollector())
            ->namespace('App\**')
            ->method('execute', 'authorize');
        $entryPoints = iterator_to_array($collector->collect($this->basePath), preserve_keys: false);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);

        foreach ($identifiers as $id) {
            $this->assertTrue(
                str_ends_with($id, '::execute') || str_ends_with($id, '::authorize'),
                "Unexpected method in: {$id}",
            );
        }
    }

    public function testNamespaceCollectorWithPublicMethods(): void
    {
        $collector = (new NamespaceCollector())
            ->namespace('App\UseCase\CreateUserUseCase')
            ->publicMethods();
        $entryPoints = iterator_to_array($collector->collect($this->basePath), preserve_keys: false);

        $this->assertNotEmpty($entryPoints);

        // All collected methods should be public (can't easily verify visibility here,
        // but we can check that some methods are collected)
        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);
        $this->assertContains('App\UseCase\CreateUserUseCase::execute', $identifiers);
    }

    public function testNamespaceCollectorSkipsMagicMethods(): void
    {
        $collector = (new NamespaceCollector())->namespace('App\UseCase\CreateUserUseCase');
        $entryPoints = iterator_to_array($collector->collect($this->basePath), preserve_keys: false);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);

        // Should not include __construct
        foreach ($identifiers as $id) {
            $this->assertStringNotContainsString('__construct', $id);
        }
    }

    public function testNamespaceCollectorMultiplePatterns(): void
    {
        $collector = (new NamespaceCollector())
            ->namespace('App\UseCase\CreateUserUseCase')
            ->namespace('App\UseCase\DeleteUserUseCase');
        $entryPoints = iterator_to_array($collector->collect($this->basePath), preserve_keys: false);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);
        $this->assertContains('App\UseCase\CreateUserUseCase::execute', $identifiers);
        $this->assertContains('App\UseCase\DeleteUserUseCase::execute', $identifiers);
    }

    // ========================================
    // CompositeCollector Tests
    // ========================================

    public function testCompositeCollectorOr(): void
    {
        $collector1 = (new NamespaceCollector())->namespace('App\UseCase\CreateUserUseCase');
        $collector2 = (new NamespaceCollector())->namespace('App\UseCase\DeleteUserUseCase');

        $composite = CompositeCollector::or($collector1, $collector2);
        $entryPoints = iterator_to_array($composite->collect($this->basePath), preserve_keys: false);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);
        $this->assertContains('App\UseCase\CreateUserUseCase::execute', $identifiers);
        $this->assertContains('App\UseCase\DeleteUserUseCase::execute', $identifiers);
    }

    public function testCompositeCollectorOrDeduplicates(): void
    {
        $collector1 = (new NamespaceCollector())->namespace('App\UseCase\CreateUserUseCase');
        $collector2 = (new NamespaceCollector())->namespace('App\UseCase\CreateUserUseCase');

        $composite = CompositeCollector::or($collector1, $collector2);
        $entryPoints = iterator_to_array($composite->collect($this->basePath), preserve_keys: false);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);
        $executeCount = count(array_filter(
            $identifiers,
            static fn($id) => $id === 'App\UseCase\CreateUserUseCase::execute',
        ));

        $this->assertSame(1, $executeCount, 'Should deduplicate identical entry points');
    }

    public function testCompositeCollectorAnd(): void
    {
        // Both collectors return CreateUserUseCase::execute
        $collector1 = (new NamespaceCollector())->namespace('App\UseCase\CreateUserUseCase');
        $collector2 = (new NamespaceCollector())->namespace('App\UseCase\*');

        $composite = CompositeCollector::and($collector1, $collector2);
        $entryPoints = iterator_to_array($composite->collect($this->basePath), preserve_keys: false);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);
        $this->assertContains('App\UseCase\CreateUserUseCase::execute', $identifiers);
    }

    public function testCompositeCollectorAndReturnsOnlyIntersection(): void
    {
        $collector1 = (new NamespaceCollector())->namespace('App\UseCase\CreateUserUseCase');
        $collector2 = (new NamespaceCollector())->namespace('App\UseCase\DeleteUserUseCase');

        $composite = CompositeCollector::and($collector1, $collector2);
        $entryPoints = iterator_to_array($composite->collect($this->basePath), preserve_keys: false);

        // Should be empty since there's no overlap
        $this->assertEmpty($entryPoints);
    }

    public function testCompositeCollectorAndWithEmptyCollectors(): void
    {
        $composite = CompositeCollector::and();
        $entryPoints = iterator_to_array($composite->collect($this->basePath), preserve_keys: false);

        $this->assertEmpty($entryPoints);
    }

    // ========================================
    // ExcludingCollector Tests
    // ========================================

    public function testExcludingCollector(): void
    {
        $base = (new NamespaceCollector())->namespace('App\UseCase\*');
        $exclusion = (new NamespaceCollector())->namespace('App\UseCase\DeleteUserUseCase');

        $excluding = new ExcludingCollector($base, $exclusion);
        $entryPoints = iterator_to_array($excluding->collect($this->basePath), preserve_keys: false);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);

        $this->assertContains('App\UseCase\CreateUserUseCase::execute', $identifiers);
        $this->assertNotContains('App\UseCase\DeleteUserUseCase::execute', $identifiers);
    }

    public function testExcludingCollectorWithNoMatches(): void
    {
        $base = (new NamespaceCollector())->namespace('App\UseCase\CreateUserUseCase');
        $exclusion = (new NamespaceCollector())->namespace('App\NonExistent\*');

        $excluding = new ExcludingCollector($base, $exclusion);
        $entryPoints = iterator_to_array($excluding->collect($this->basePath), preserve_keys: false);

        $identifiers = array_map(static fn($e) => $e->getIdentifier(), $entryPoints);
        $this->assertContains('App\UseCase\CreateUserUseCase::execute', $identifiers);
    }

    // ========================================
    // EntryPoint Tests
    // ========================================

    public function testEntryPointGetIdentifier(): void
    {
        $entryPoint = new EntryPoint('App\Foo', 'bar', '/path/to/file.php');

        $this->assertSame('App\Foo::bar', $entryPoint->getIdentifier());
    }

    public function testEntryPointToStringWithoutDescription(): void
    {
        $entryPoint = new EntryPoint('App\Foo', 'bar', '/path/to/file.php');

        $this->assertSame('App\Foo::bar', (string) $entryPoint);
    }

    public function testEntryPointToStringWithDescription(): void
    {
        $entryPoint = new EntryPoint('App\Foo', 'bar', '/path/to/file.php', 'Custom description');

        $this->assertSame('Custom description', (string) $entryPoint);
    }
}
