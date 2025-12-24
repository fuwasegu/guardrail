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

            /** @var list<string> Stack of route prefixes from Route::prefix()->group() */
            private array $prefixStack = [];

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

                // Check for Route::prefix('xxx')->group(closure) or Route::group(['prefix' => 'xxx'], closure)
                if ($node instanceof Node\Expr\MethodCall) {
                    $prefix = $this->extractPrefixFromGroupCall($node);
                    if ($prefix !== null) {
                        $this->prefixStack[] = $prefix;
                        // Process the group's closure manually to track prefix scope
                        $this->processGroupClosure($node);
                        array_pop($this->prefixStack);
                        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }
                }

                // Look for Route::method() calls
                if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
                    $this->processRouteCall($node);
                }

                return null;
            }

            /**
             * Extract prefix from Route::prefix('xxx')->group() or Route::group(['prefix' => 'xxx'], ...)
             */
            private function extractPrefixFromGroupCall(Node\Expr\MethodCall $node): ?string
            {
                if (!$node->name instanceof Node\Identifier || $node->name->toString() !== 'group') {
                    return null;
                }

                // Check for Route::prefix('xxx')->group() pattern (chained method calls)
                if ($node->var instanceof Node\Expr\MethodCall) {
                    return $this->extractPrefixFromChain($node->var);
                }

                // Check for Route::prefix('xxx')->group() where prefix is directly on Route::
                if ($node->var instanceof Node\Expr\StaticCall) {
                    if (!$node->var->class instanceof Node\Name || $node->var->class->toString() !== 'Route') {
                        return null;
                    }

                    // Route::prefix('xxx')->group()
                    if ($node->var->name instanceof Node\Identifier && $node->var->name->toString() === 'prefix') {
                        $args = $node->var->args;
                        if (
                            $args !== []
                            && $args[0] instanceof Node\Arg
                            && $args[0]->value instanceof Node\Scalar\String_
                        ) {
                            return $args[0]->value->value;
                        }
                    }

                    // Route::group(['prefix' => 'xxx'], ...) pattern
                    if ($node->var->name instanceof Node\Identifier && $node->var->name->toString() === 'group') {
                        $args = $node->args;
                        if (
                            $args !== []
                            && $args[0] instanceof Node\Arg
                            && $args[0]->value instanceof Node\Expr\Array_
                        ) {
                            return $this->extractPrefixFromArray($args[0]->value);
                        }
                    }
                }

                return null;
            }

            /**
             * Extract prefix from chained calls like Route::prefix('api')->middleware(...)->group()
             */
            private function extractPrefixFromChain(Node\Expr $expr): ?string
            {
                while ($expr instanceof Node\Expr\MethodCall) {
                    if ($expr->name instanceof Node\Identifier && $expr->name->toString() === 'prefix') {
                        $args = $expr->args;
                        if (
                            $args !== []
                            && $args[0] instanceof Node\Arg
                            && $args[0]->value instanceof Node\Scalar\String_
                        ) {
                            return $args[0]->value->value;
                        }
                    }
                    $expr = $expr->var;
                }

                // Check if chain starts with Route::prefix()
                if ($expr instanceof Node\Expr\StaticCall) {
                    if ($expr->class instanceof Node\Name && $expr->class->toString() === 'Route') {
                        if ($expr->name instanceof Node\Identifier && $expr->name->toString() === 'prefix') {
                            $args = $expr->args;
                            if (
                                $args !== []
                                && $args[0] instanceof Node\Arg
                                && $args[0]->value instanceof Node\Scalar\String_
                            ) {
                                return $args[0]->value->value;
                            }
                        }
                    }
                }

                return null;
            }

            /**
             * Extract prefix from array like ['prefix' => 'api']
             */
            private function extractPrefixFromArray(Node\Expr\Array_ $array): ?string
            {
                foreach ($array->items as $item) {
                    if (!$item instanceof Node\Expr\ArrayItem || $item->key === null) {
                        continue;
                    }
                    if ($item->key instanceof Node\Scalar\String_ && $item->key->value === 'prefix') {
                        if ($item->value instanceof Node\Scalar\String_) {
                            return $item->value->value;
                        }
                    }
                }
                return null;
            }

            /**
             * Process the closure inside a group() call
             */
            private function processGroupClosure(Node\Expr\MethodCall $groupCall): void
            {
                foreach ($groupCall->args as $arg) {
                    if (!$arg instanceof Node\Arg) {
                        continue;
                    }

                    $nodes = null;
                    if ($arg->value instanceof Node\Expr\Closure) {
                        $nodes = $arg->value->stmts;
                    } elseif ($arg->value instanceof Node\Expr\ArrowFunction) {
                        $nodes = [$arg->value->expr];
                    }

                    if ($nodes !== null) {
                        $traverser = new NodeTraverser();
                        $traverser->addVisitor($this);
                        $traverser->traverse($nodes);
                    }
                }
            }

            private function processRouteCall(Node\Expr\MethodCall|Node\Expr\StaticCall $node): void
            {
                if (!$this->isRouteCall($node)) {
                    return;
                }

                // Extract route path from first argument (e.g., '/users')
                $routePath = $this->extractRoutePath($node);

                // Prepend accumulated prefixes
                $fullPath = $this->buildFullPath($routePath);

                // Extract [Controller::class, 'method'] from arguments
                foreach ($node->args as $arg) {
                    if (!$arg instanceof Node\Arg) {
                        continue;
                    }

                    $entryPoint = $this->extractControllerAction($arg->value, $fullPath);
                    if ($entryPoint !== null) {
                        $this->entryPoints[] = $entryPoint;
                    }
                }
            }

            private function buildFullPath(?string $routePath): ?string
            {
                if ($this->prefixStack === [] && $routePath === null) {
                    return null;
                }

                $parts = [];
                foreach ($this->prefixStack as $prefix) {
                    $parts[] = trim($prefix, characters: '/');
                }
                if ($routePath !== null) {
                    $parts[] = trim($routePath, characters: '/');
                }

                return '/' . implode('/', array_filter($parts));
            }

            private function extractRoutePath(Node\Expr\MethodCall|Node\Expr\StaticCall $node): ?string
            {
                $args = $node->args;
                if ($args === [] || !$args[0] instanceof Node\Arg) {
                    return null;
                }

                $firstArg = $args[0]->value;
                if ($firstArg instanceof Node\Scalar\String_) {
                    return $firstArg->value;
                }

                return null;
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

            private function extractControllerAction(Node\Expr $expr, ?string $routePath): ?EntryPoint
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

                $description = $routePath !== null
                    ? "Route: {$routePath} → {$controllerClass}::{$methodName}"
                    : "Route: {$controllerClass}::{$methodName}";

                return new EntryPoint(
                    className: $controllerClass,
                    methodName: $methodName,
                    filePath: $this->resolveControllerFilePath($controllerClass),
                    description: $description,
                    routePath: $routePath,
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
