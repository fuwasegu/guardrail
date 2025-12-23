<?php

declare(strict_types=1);

namespace Guardrail\Collector;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Collects entry points based on namespace patterns.
 */
final class NamespaceCollector implements CollectorInterface
{
    /** @var list<string> */
    private array $patterns = [];

    /** @var list<string> */
    private array $methodFilters = [];

    private bool $publicOnly = false;

    public function namespace(string $pattern): self
    {
        $clone = clone $this;
        $clone->patterns[] = $pattern;
        return $clone;
    }

    public function method(string ...$methods): self
    {
        $clone = clone $this;
        $clone->methodFilters = array_merge($clone->methodFilters, $methods);
        return $clone;
    }

    public function publicMethods(): self
    {
        $clone = clone $this;
        $clone->publicOnly = true;
        return $clone;
    }

    public function collect(string $basePath): iterable
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $code = $file->getContents();
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            yield from $this->extractEntryPoints($ast, $realPath);
        }
    }

    /**
     * @param array<Node> $ast
     * @return iterable<EntryPoint>
     */
    private function extractEntryPoints(array $ast, string $filePath): iterable
    {
        $visitor = new class($this->patterns, $this->methodFilters, $this->publicOnly, $filePath) extends
            NodeVisitorAbstract {
            /** @var list<EntryPoint> */
            public array $entryPoints = [];
            private ?string $currentNamespace = null;
            private ?string $currentClass = null;

            /**
             * @param list<string> $patterns
             * @param list<string> $methodFilters
             */
            public function __construct(
                private readonly array $patterns,
                private readonly array $methodFilters,
                private readonly bool $publicOnly,
                private readonly string $filePath,
            ) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                }

                if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                    $this->currentClass = $node->name->toString();
                    $fqcn = $this->currentNamespace
                        ? $this->currentNamespace . '\\' . $this->currentClass
                        : $this->currentClass;

                    if (!$this->matchesPatterns($fqcn)) {
                        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }
                }

                if ($node instanceof Node\Stmt\ClassMethod && $this->currentClass !== null) {
                    $fqcn = $this->currentNamespace
                        ? $this->currentNamespace . '\\' . $this->currentClass
                        : $this->currentClass;

                    if (!$this->matchesPatterns($fqcn)) {
                        return null;
                    }

                    $methodName = $node->name->toString();

                    // Apply method filter
                    if ($this->methodFilters !== [] && !in_array($methodName, $this->methodFilters, strict: true)) {
                        return null;
                    }

                    // Apply visibility filter
                    if ($this->publicOnly && !$node->isPublic()) {
                        return null;
                    }

                    // Skip magic methods unless explicitly requested
                    if ($this->methodFilters === [] && str_starts_with($methodName, '__')) {
                        return null;
                    }

                    $this->entryPoints[] = new EntryPoint(
                        className: $fqcn,
                        methodName: $methodName,
                        filePath: $this->filePath,
                    );
                }

                return null;
            }

            public function leaveNode(Node $node): Node|array|int|null
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $this->currentClass = null;
                }
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->currentNamespace = null;
                }
                return null;
            }

            private function matchesPatterns(string $fqcn): bool
            {
                foreach ($this->patterns as $pattern) {
                    if (!$this->matchesGlob($fqcn, $pattern)) {
                        continue;
                    }

                    return true;
                }
                return false;
            }

            private function matchesGlob(string $subject, string $pattern): bool
            {
                // Convert glob pattern to regex
                // ** matches any namespace depth
                // * matches within a single namespace segment
                $regex = str_replace(search: '\\', replace: '\\\\', subject: $pattern);
                $regex = str_replace(search: '**', replace: '{{DOUBLE_STAR}}', subject: $regex);
                $regex = str_replace(search: '*', replace: '[^\\\\]*', subject: $regex);
                $regex = str_replace(search: '{{DOUBLE_STAR}}', replace: '.*', subject: $regex);
                $regex = '/^' . $regex . '$/';

                return (bool) preg_match($regex, $subject);
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->entryPoints;
    }
}
