<?php

declare(strict_types=1);

namespace Guardrail\Collector;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Collects entry points from Laravel route files.
 *
 * Parses route definitions like:
 *   Route::get('/users', [UserController::class, 'index']);
 *   Route::post('/users', [UserController::class, 'store']);
 */
final class RouteCollector implements CollectorInterface
{
    /** @var list<string> */
    private array $routeFiles = [];

    public function routeFile(string $path): self
    {
        $clone = clone $this;
        $clone->routeFiles[] = $path;
        return $clone;
    }

    public function collect(string $basePath): iterable
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($this->routeFiles as $routeFile) {
            $fullPath = $basePath . '/' . ltrim($routeFile, characters: '/');

            if (!file_exists($fullPath)) {
                continue;
            }

            $code = file_get_contents($fullPath);
            if ($code === false) {
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

            yield from $this->extractRouteEntryPoints($ast, $basePath);
        }
    }

    /**
     * @param array<Node> $ast
     * @return iterable<EntryPoint>
     */
    private function extractRouteEntryPoints(array $ast, string $basePath): iterable
    {
        $visitor = new class($basePath) extends NodeVisitorAbstract {
            /** @var list<EntryPoint> */
            public array $entryPoints = [];

            /** @var array<string, string> Maps alias/short name to FQCN */
            private array $imports = [];

            public function __construct(
                private readonly string $basePath,
            ) {}

            public function enterNode(Node $node): ?int
            {
                // Collect use statements
                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $fqcn = $use->name->toString();
                        $alias = $use->alias?->toString() ?? $use->name->getLast();
                        $this->imports[$alias] = $fqcn;
                    }
                }

                // Look for Route::method() calls
                if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
                    $this->processRouteCall($node);
                }

                return null;
            }

            private function processRouteCall(Node\Expr\MethodCall|Node\Expr\StaticCall $node): void
            {
                if (!$this->isRouteCall($node)) {
                    return;
                }

                // Extract [Controller::class, 'method'] from arguments
                foreach ($node->args as $arg) {
                    if (!$arg instanceof Node\Arg) {
                        continue;
                    }

                    $entryPoint = $this->extractControllerAction($arg->value);
                    if ($entryPoint !== null) {
                        $this->entryPoints[] = $entryPoint;
                    }
                }
            }

            private function isRouteCall(Node\Expr\MethodCall|Node\Expr\StaticCall $node): bool
            {
                $routeMethods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match'];

                // Check method name
                if (!$node->name instanceof Node\Identifier) {
                    return false;
                }

                $methodName = $node->name->toString();

                // Direct Route::get() style
                if ($node instanceof Node\Expr\StaticCall) {
                    if (!$node->class instanceof Node\Name) {
                        return false;
                    }
                    $className = $node->class->toString();
                    return $className === 'Route' && in_array($methodName, $routeMethods, strict: true);
                }

                // Chained call like Route::prefix()->get()
                return in_array($methodName, $routeMethods, strict: true);
            }

            private function extractControllerAction(Node\Expr $expr): ?EntryPoint
            {
                // Looking for array syntax: [Controller::class, 'method']
                if (!$expr instanceof Node\Expr\Array_) {
                    return null;
                }

                if (count($expr->items) !== 2) {
                    return null;
                }

                $firstItem = $expr->items[0];
                $secondItem = $expr->items[1];

                if (!$firstItem instanceof Node\Expr\ArrayItem || !$secondItem instanceof Node\Expr\ArrayItem) {
                    return null;
                }

                // First element: Controller::class
                $controllerClass = $this->resolveClassConstFetch($firstItem->value);
                if ($controllerClass === null) {
                    return null;
                }

                // Second element: 'method' string
                if (!$secondItem->value instanceof Node\Scalar\String_) {
                    return null;
                }
                $methodName = $secondItem->value->value;

                return new EntryPoint(
                    className: $controllerClass,
                    methodName: $methodName,
                    filePath: $this->resolveControllerFilePath($controllerClass),
                    description: "Route: {$controllerClass}::{$methodName}",
                );
            }

            private function resolveClassConstFetch(Node\Expr $expr): ?string
            {
                if (!$expr instanceof Node\Expr\ClassConstFetch) {
                    return null;
                }

                if (!$expr->name instanceof Node\Identifier || $expr->name->toString() !== 'class') {
                    return null;
                }

                if (!$expr->class instanceof Node\Name) {
                    return null;
                }

                $className = $expr->class->toString();

                // Resolve from imports
                $parts = explode('\\', $className);
                $firstPart = $parts[0];

                if (isset($this->imports[$firstPart])) {
                    if (count($parts) === 1) {
                        return $this->imports[$firstPart];
                    }
                    // Replace first part with imported FQCN
                    $parts[0] = $this->imports[$firstPart];
                    return implode('\\', $parts);
                }

                // Already FQCN or unresolvable
                return $className;
            }

            private function resolveControllerFilePath(string $className): string
            {
                // Convert FQCN to PSR-4 path (assuming App\ maps to app/)
                $relativePath = str_replace(search: '\\', replace: '/', subject: $className) . '.php';

                // Common Laravel convention: App\ -> app/
                if (str_starts_with($relativePath, 'App/')) {
                    $relativePath = 'app/' . substr($relativePath, offset: 4);
                }

                $fullPath = $this->basePath . '/' . $relativePath;

                if (file_exists($fullPath)) {
                    return $fullPath;
                }

                // Return best guess path
                return $relativePath;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->entryPoints;
    }
}
