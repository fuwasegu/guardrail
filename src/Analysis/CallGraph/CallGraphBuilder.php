<?php

declare(strict_types=1);

namespace Guardrail\Analysis\CallGraph;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Builds a call graph by analyzing PHP source files.
 *
 * Supported:
 *   - Parent class method calls: $this->parentMethod() is resolved to the parent class.
 *   - Trait method calls: $this->traitMethod() is resolved to the trait.
 *   - Multi-level inheritance: Methods in grandparent classes are resolved.
 *   - Trait property access: Properties declared in using classes are resolved.
 *
 * [LIMITATION]: Dynamic method calls cannot be analyzed.
 *   - $obj->$method(), call_user_func(), etc. are not detectable statically.
 *
 * [LIMITATION]: Interface type hints are not resolved to concrete implementations.
 *   - When a property is typed as an interface, calls on it cannot be traced.
 */
final class CallGraphBuilder
{
    private CallGraph $callGraph;

    public function __construct()
    {
        $this->callGraph = new CallGraph();
    }

    public function build(string $basePath): CallGraph
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        // Collect all ASTs first
        $allAsts = [];
        foreach ($finder as $file) {
            $code = $file->getContents();

            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $allAsts[] = $ast;
        }

        // Pass 1: Collect class/trait definitions and properties
        foreach ($allAsts as $ast) {
            $this->collectDefinitions($ast);
        }

        // Pass 2: Analyze method calls
        foreach ($allAsts as $ast) {
            $this->analyzeCalls($ast);
        }

        return $this->callGraph;
    }

    /**
     * Pass 1: Collect class/trait definitions, properties, and inheritance relationships.
     *
     * @param array<Node> $ast
     */
    private function collectDefinitions(array $ast): void
    {
        $visitor = new DefinitionCollectorVisitor($this->callGraph);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }

    /**
     * Pass 2: Analyze method calls.
     *
     * @param array<Node> $ast
     */
    private function analyzeCalls(array $ast): void
    {
        $visitor = new CallAnalyzerVisitor($this->callGraph);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}

/**
 * Pass 1: Collects class/trait definitions, properties, and inheritance relationships.
 *
 * @internal
 */
final class DefinitionCollectorVisitor extends NodeVisitorAbstract
{
    use NameResolverTrait;

    public function __construct(
        private readonly CallGraph $callGraph,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString();
            $this->useStatements = [];
        }

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $fullName = $use->name->toString();
                $alias = $use->alias?->toString() ?? $use->name->getLast();
                $this->useStatements[$alias] = $fullName;
            }
        }

        if ($node instanceof Node\Stmt\Class_) {
            $this->enterClass($node);
        }

        if ($node instanceof Node\Stmt\Trait_) {
            $this->enterTrait($node);
        }

        return null;
    }

    public function leaveNode(Node $node): Node|array|int|null
    {
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            $this->currentClass = null;
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
            $this->useStatements = [];
        }

        return null;
    }

    private function enterTrait(Node\Stmt\Trait_ $node): void
    {
        $nodeName = $node->name;
        if ($nodeName === null) {
            return;
        }

        $traitName = $nodeName->toString();
        $this->currentClass = $this->currentNamespace !== null
            ? $this->currentNamespace . '\\' . $traitName
            : $traitName;

        $currentClass = $this->currentClass;

        // Mark as trait for later lookup
        $this->callGraph->markAsTrait($currentClass);

        // Record method definitions in trait
        foreach ($node->getMethods() as $method) {
            $this->callGraph->addMethodDefinition($currentClass, $method->name->toString());
        }

        // Collect property types in trait
        foreach ($node->getProperties() as $property) {
            $type = $this->resolveType($property->type);
            if ($type !== null) {
                foreach ($property->props as $prop) {
                    $propName = $prop->name->toString();
                    $this->callGraph->addPropertyType($currentClass, $propName, $type);
                }
            }
        }
    }

    private function enterClass(Node\Stmt\Class_ $node): void
    {
        $nodeName = $node->name;
        if ($nodeName === null) {
            return;
        }

        $className = $nodeName->toString();
        $this->currentClass = $this->currentNamespace !== null
            ? $this->currentNamespace . '\\' . $className
            : $className;

        $currentClass = $this->currentClass;

        // Record parent class
        $parentClass = null;
        if ($node->extends !== null) {
            $parentClass = $this->resolveName($node->extends);
        }
        $this->callGraph->setClassParent($currentClass, $parentClass);

        // Record used traits
        $traits = [];
        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $traits[] = $this->resolveName($trait);
            }
        }
        $this->callGraph->setClassTraits($currentClass, $traits);

        // Collect property types
        foreach ($node->getProperties() as $property) {
            $type = $this->resolveType($property->type);
            if ($type !== null) {
                foreach ($property->props as $prop) {
                    $propName = $prop->name->toString();
                    $this->callGraph->addPropertyType($currentClass, $propName, $type);
                }
            }
        }

        // Record method definitions and collect constructor promoted properties
        foreach ($node->getMethods() as $method) {
            $this->callGraph->addMethodDefinition($currentClass, $method->name->toString());

            // Collect constructor promoted properties
            if ($method->name->toString() === '__construct') {
                foreach ($method->params as $param) {
                    if ($param->flags === 0 || !$param->var instanceof Node\Expr\Variable) {
                        continue;
                    }

                    $paramName = is_string($param->var->name) ? $param->var->name : null;
                    $type = $this->resolveType($param->type);
                    if ($paramName !== null && $type !== null) {
                        $this->callGraph->addPropertyType($currentClass, $paramName, $type);
                    }
                }
            }
        }
    }
}

/**
 * Pass 2: Analyzes method calls.
 *
 * @internal
 */
final class CallAnalyzerVisitor extends NodeVisitorAbstract
{
    use NameResolverTrait;

    private ?string $currentMethod = null;

    /** @var array<string, string> Parameter/variable name => type in current method */
    private array $currentMethodVariables = [];

    public function __construct(
        private readonly CallGraph $callGraph,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString();
            $this->useStatements = [];
        }

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $fullName = $use->name->toString();
                $alias = $use->alias?->toString() ?? $use->name->getLast();
                $this->useStatements[$alias] = $fullName;
            }
        }

        if ($node instanceof Node\Stmt\Class_) {
            $this->enterClass($node);
        }

        if ($node instanceof Node\Stmt\Trait_) {
            $this->enterTrait($node);
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->enterMethod($node);
        }

        if ($node instanceof Node\Expr\MethodCall && $this->currentMethod !== null) {
            $this->analyzeMethodCall($node);
        }

        if ($node instanceof Node\Expr\StaticCall && $this->currentMethod !== null) {
            $this->analyzeStaticCall($node);
        }

        return null;
    }

    public function leaveNode(Node $node): Node|array|int|null
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = null;
            $this->currentMethodVariables = [];
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            $this->currentClass = null;
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
            $this->useStatements = [];
        }

        return null;
    }

    private function enterTrait(Node\Stmt\Trait_ $node): void
    {
        $nodeName = $node->name;
        if ($nodeName === null) {
            return;
        }

        $traitName = $nodeName->toString();
        $this->currentClass = $this->currentNamespace !== null
            ? $this->currentNamespace . '\\' . $traitName
            : $traitName;
    }

    private function enterClass(Node\Stmt\Class_ $node): void
    {
        $nodeName = $node->name;
        if ($nodeName === null) {
            return;
        }

        $className = $nodeName->toString();
        $this->currentClass = $this->currentNamespace !== null
            ? $this->currentNamespace . '\\' . $className
            : $className;
    }

    private function enterMethod(Node\Stmt\ClassMethod $node): void
    {
        $this->currentMethod = $node->name->toString();
        $this->currentMethodVariables = [];

        foreach ($node->params as $param) {
            if (!($param->var instanceof Node\Expr\Variable && is_string($param->var->name))) {
                continue;
            }

            $type = $this->resolveType($param->type);
            if ($type !== null) {
                $this->currentMethodVariables[$param->var->name] = $type;
            }
        }
    }

    private function analyzeMethodCall(Node\Expr\MethodCall $node): void
    {
        if (!$node->name instanceof Node\Identifier) {
            return;
        }

        $currentClass = $this->currentClass;
        $currentMethod = $this->currentMethod;
        if ($currentClass === null || $currentMethod === null) {
            return;
        }

        $methodName = $node->name->toString();
        $calleeClass = $this->resolveCalleeClass($node->var);
        $variableName = $this->getVariableName($node->var);

        // For $this->method() calls, resolve where the method is actually defined
        if ($calleeClass === $currentClass) {
            $resolvedClass = $this->callGraph->resolveMethodClass($currentClass, $methodName);
            if ($resolvedClass !== null) {
                $calleeClass = $resolvedClass;
            }
        }

        $call = new MethodCall(
            callerClass: $currentClass,
            callerMethod: $currentMethod,
            calleeClass: $calleeClass,
            calleeMethod: $methodName,
            line: $node->getLine(),
            isStatic: false,
            variableName: $variableName,
        );

        $this->callGraph->addCall($call);
    }

    private function analyzeStaticCall(Node\Expr\StaticCall $node): void
    {
        if (!$node->name instanceof Node\Identifier) {
            return;
        }

        $currentClass = $this->currentClass;
        $currentMethod = $this->currentMethod;
        if ($currentClass === null || $currentMethod === null) {
            return;
        }

        $methodName = $node->name->toString();
        $calleeClass = null;

        if ($node->class instanceof Node\Name) {
            $calleeClass = $this->resolveName($node->class);
        }

        $call = new MethodCall(
            callerClass: $currentClass,
            callerMethod: $currentMethod,
            calleeClass: $calleeClass,
            calleeMethod: $methodName,
            line: $node->getLine(),
            isStatic: true,
        );

        $this->callGraph->addCall($call);
    }

    private function resolveCalleeClass(Node\Expr $var): ?string
    {
        $currentClass = $this->currentClass;

        // $this->property->method()
        if ($var instanceof Node\Expr\PropertyFetch) {
            if (
                $var->var instanceof Node\Expr\Variable
                && $var->var->name === 'this'
                && $var->name instanceof Node\Identifier
            ) {
                $propertyName = $var->name->toString();

                // Look up property type from CallGraph (works for traits too)
                if ($currentClass !== null) {
                    $type = $this->callGraph->resolvePropertyType($currentClass, $propertyName);
                    if ($type !== null) {
                        return $type;
                    }
                }
            }
        }

        // $this->method() - call on same class
        if ($var instanceof Node\Expr\Variable && $var->name === 'this') {
            return $currentClass;
        }

        // $variable->method()
        if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
            return $this->currentMethodVariables[$var->name] ?? null;
        }

        return null;
    }

    private function getVariableName(Node\Expr $var): ?string
    {
        if ($var instanceof Node\Expr\PropertyFetch && $var->name instanceof Node\Identifier) {
            return '$this->' . $var->name->toString();
        }

        if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
            return '$' . $var->name;
        }

        return null;
    }
}
