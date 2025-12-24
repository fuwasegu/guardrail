<?php

declare(strict_types=1);

namespace Guardrail\Analysis\CallGraph;

use Guardrail\Config\ScanConfig;
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
 *   - Interface implementation resolution: Interface method calls are traced to all implementing classes.
 *
 * [LIMITATION]: Dynamic method calls cannot be analyzed.
 *   - $obj->$method(), call_user_func(), etc. are not detectable statically.
 */
final class CallGraphBuilder
{
    private CallGraph $callGraph;
    private ClassHierarchy $classHierarchy;
    private TypeRegistry $typeRegistry;

    public function __construct()
    {
        $this->callGraph = new CallGraph();
        $this->classHierarchy = new ClassHierarchy();
        $this->typeRegistry = new TypeRegistry($this->classHierarchy);
    }

    public function build(string $basePath, ?ScanConfig $scanConfig = null): CallGraph
    {
        $scanConfig ??= ScanConfig::default();

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = $this->createFinder($basePath, $scanConfig);

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

        // Pass 1: Collect class/trait/interface definitions and properties
        foreach ($allAsts as $ast) {
            $this->collectDefinitions($ast);
        }

        // Pass 2: Analyze method calls
        foreach ($allAsts as $ast) {
            $this->analyzeCalls($ast);
        }

        // Pass 3: Link interface methods to implementing class methods
        $this->linkInterfaceImplementations();

        return $this->callGraph;
    }

    /**
     * Pass 3: Create virtual calls from interface methods to implementing class methods.
     * This allows call graph traversal through interface type hints.
     */
    private function linkInterfaceImplementations(): void
    {
        // Get all methods from the call graph
        $allMethods = $this->callGraph->getAllMethods();

        foreach ($allMethods as $method) {
            [$className, $methodName] = explode('::', $method);

            // Check if this is an interface method
            if (!$this->classHierarchy->isInterface($className)) {
                continue;
            }

            // Find all classes implementing this interface
            $implementingClasses = $this->classHierarchy->findClassesImplementing($className);

            // Create virtual calls from interface method to implementing class methods
            foreach ($implementingClasses as $implementingClass) {
                // Verify the implementing class has this method
                if (!$this->classHierarchy->hasMethodDefinition($implementingClass, $methodName)) {
                    continue;
                }

                // Create a virtual call: Interface::method -> ImplementingClass::method
                $call = new MethodCall(
                    callerClass: $className,
                    callerMethod: $methodName,
                    calleeClass: $implementingClass,
                    calleeMethod: $methodName,
                    line: 0, // Virtual call, no actual line
                    isStatic: false,
                );

                $this->callGraph->addCall($call);
            }
        }
    }

    /**
     * Create a Finder configured with scan paths and excludes.
     */
    private function createFinder(string $basePath, ScanConfig $scanConfig): Finder
    {
        $finder = new Finder();
        $finder->files()->name('*.php');

        // Determine which paths to scan
        $scanPaths = [];
        foreach ($scanConfig->paths as $path) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $path;
            if (is_dir($fullPath)) {
                $scanPaths[] = $fullPath;
            }
        }

        // If no valid paths found, fall back to base path
        if ($scanPaths === []) {
            $scanPaths[] = $basePath;
        }

        $finder->in($scanPaths);

        // Apply exclude patterns
        foreach ($scanConfig->excludes as $exclude) {
            $finder->notPath($exclude);
        }

        return $finder;
    }

    /**
     * Pass 1: Collect class/trait definitions, properties, and inheritance relationships.
     *
     * @param array<Node> $ast
     */
    private function collectDefinitions(array $ast): void
    {
        $visitor = new DefinitionCollectorVisitor($this->classHierarchy, $this->typeRegistry);
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
        $visitor = new CallAnalyzerVisitor($this->callGraph, $this->classHierarchy, $this->typeRegistry);
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
        private readonly ClassHierarchy $classHierarchy,
        private readonly TypeRegistry $typeRegistry,
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

        if ($node instanceof Node\Stmt\Interface_) {
            $this->enterInterface($node);
        }

        return null;
    }

    public function leaveNode(Node $node): Node|array|int|null
    {
        if (
            $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Interface_
        ) {
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
        $this->classHierarchy->markAsTrait($currentClass);

        // Record method definitions and return types in trait
        foreach ($node->getMethods() as $method) {
            $methodName = $method->name->toString();
            $this->classHierarchy->addMethodDefinition($currentClass, $methodName);

            // Collect return type
            $returnType = $this->resolveType($method->returnType);
            if ($returnType !== null) {
                $this->classHierarchy->addMethodReturnType($currentClass, $methodName, $returnType);
            }
        }

        // Collect property types in trait
        foreach ($node->getProperties() as $property) {
            $type = $this->resolveType($property->type);
            if ($type !== null) {
                foreach ($property->props as $prop) {
                    $propName = $prop->name->toString();
                    $this->typeRegistry->addPropertyType($currentClass, $propName, $type);
                }
            }
        }
    }

    private function enterInterface(Node\Stmt\Interface_ $node): void
    {
        $nodeName = $node->name;
        if ($nodeName === null) {
            return;
        }

        $interfaceName = $nodeName->toString();
        $this->currentClass = $this->currentNamespace !== null
            ? $this->currentNamespace . '\\' . $interfaceName
            : $interfaceName;

        $currentClass = $this->currentClass;

        // Mark as interface
        $this->classHierarchy->markAsInterface($currentClass);

        // Record method definitions and return types in interface
        foreach ($node->getMethods() as $method) {
            $methodName = $method->name->toString();
            $this->classHierarchy->addMethodDefinition($currentClass, $methodName);

            // Collect return type
            $returnType = $this->resolveType($method->returnType);
            if ($returnType !== null) {
                $this->classHierarchy->addMethodReturnType($currentClass, $methodName, $returnType);
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
        $this->classHierarchy->setClassParent($currentClass, $parentClass);

        // Record implemented interfaces
        $interfaces = [];
        foreach ($node->implements as $implement) {
            $interfaces[] = $this->resolveName($implement);
        }
        $this->classHierarchy->setClassInterfaces($currentClass, $interfaces);

        // Record used traits
        $traits = [];
        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $traits[] = $this->resolveName($trait);
            }
        }
        $this->classHierarchy->setClassTraits($currentClass, $traits);

        // Collect property types
        foreach ($node->getProperties() as $property) {
            $type = $this->resolveType($property->type);
            if ($type !== null) {
                foreach ($property->props as $prop) {
                    $propName = $prop->name->toString();
                    $this->typeRegistry->addPropertyType($currentClass, $propName, $type);
                }
            }
        }

        // Record method definitions, return types, and collect constructor promoted properties
        foreach ($node->getMethods() as $method) {
            $methodName = $method->name->toString();
            $this->classHierarchy->addMethodDefinition($currentClass, $methodName);

            // Collect return type
            $returnType = $this->resolveType($method->returnType);
            if ($returnType !== null) {
                $this->classHierarchy->addMethodReturnType($currentClass, $methodName, $returnType);
            }

            // Collect constructor promoted properties
            if ($methodName === '__construct') {
                foreach ($method->params as $param) {
                    if ($param->flags === 0 || !$param->var instanceof Node\Expr\Variable) {
                        continue;
                    }

                    $paramName = is_string($param->var->name) ? $param->var->name : null;
                    $type = $this->resolveType($param->type);
                    if ($paramName !== null && $type !== null) {
                        $this->typeRegistry->addPropertyType($currentClass, $paramName, $type);
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
        private readonly ClassHierarchy $classHierarchy,
        private readonly TypeRegistry $typeRegistry,
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

        // Track variable assignments for data flow analysis
        if ($node instanceof Node\Expr\Assign && $this->currentMethod !== null) {
            $this->processAssignment($node);
        }

        if (
            ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
            && $this->currentMethod !== null
        ) {
            $this->analyzeMethodCall($node);
        }

        if ($node instanceof Node\Expr\StaticCall && $this->currentMethod !== null) {
            $this->analyzeStaticCall($node);
        }

        // Handle $obj($args) as $obj->__invoke($args)
        if ($node instanceof Node\Expr\FuncCall && $this->currentMethod !== null) {
            $this->analyzeInvocableCall($node);
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

    private function analyzeMethodCall(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node): void
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
            $resolvedClass = $this->classHierarchy->resolveMethodClass($currentClass, $methodName);
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
            $nameStr = $node->class->toString();

            // Handle parent:: specially - resolve to actual parent class
            if ($nameStr === 'parent') {
                $calleeClass = $this->classHierarchy->getClassParent($currentClass);
            }

            if ($nameStr !== 'parent') {
                $calleeClass = $this->resolveName($node->class);
            }
        }

        // For self:: and static:: calls, resolve where the method is actually defined
        // (method might be inherited from a parent class)
        if ($calleeClass === $currentClass) {
            $resolvedClass = $this->classHierarchy->resolveMethodClass($currentClass, $methodName);
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
            isStatic: true,
        );

        $this->callGraph->addCall($call);
    }

    /**
     * Analyze invocable call: $obj($args) is equivalent to $obj->__invoke($args)
     * Handles both $variable($args) and ($this->property)($args) patterns
     */
    private function analyzeInvocableCall(Node\Expr\FuncCall $node): void
    {
        $currentClass = $this->currentClass;
        $currentMethod = $this->currentMethod;
        if ($currentClass === null || $currentMethod === null) {
            return;
        }

        $calleeClass = null;
        $variableName = null;

        // Handle $variable($args) pattern
        if ($node->name instanceof Node\Expr\Variable && is_string($node->name->name)) {
            $varName = $node->name->name;
            $calleeClass = $this->currentMethodVariables[$varName] ?? null;
            $variableName = '$' . $varName;
        }

        // Handle ($this->property)($args) pattern
        if (
            $node->name instanceof Node\Expr\PropertyFetch
            && $node->name->var instanceof Node\Expr\Variable
            && $node->name->var->name === 'this'
            && $node->name->name instanceof Node\Identifier
        ) {
            $propertyName = $node->name->name->toString();
            $calleeClass = $this->typeRegistry->resolvePropertyType($currentClass, $propertyName);
            $variableName = '$this->' . $propertyName;
        }

        if ($calleeClass === null) {
            return;
        }

        // Create a call to __invoke
        $call = new MethodCall(
            callerClass: $currentClass,
            callerMethod: $currentMethod,
            calleeClass: $calleeClass,
            calleeMethod: '__invoke',
            line: $node->getLine(),
            isStatic: false,
            variableName: $variableName,
        );

        $this->callGraph->addCall($call);
    }

    private function resolveCalleeClass(Node\Expr $var): ?string
    {
        $currentClass = $this->currentClass;

        // $this->property->method() or $this->obj->property->method() (nested property chain)
        if ($var instanceof Node\Expr\PropertyFetch && $var->name instanceof Node\Identifier) {
            $propertyName = $var->name->toString();

            // $this->property
            if (
                $var->var instanceof Node\Expr\Variable
                && $var->var->name === 'this'
                && $currentClass !== null
            ) {
                return $this->typeRegistry->resolvePropertyType($currentClass, $propertyName);
            }

            // $this->obj->property or $var->property (nested/chained property access)
            $ownerClass = $this->resolveCalleeClass($var->var);
            if ($ownerClass !== null) {
                return $this->typeRegistry->resolvePropertyType($ownerClass, $propertyName);
            }

            return null;
        }

        // $this->method() - call on same class
        if ($var instanceof Node\Expr\Variable && $var->name === 'this') {
            return $currentClass;
        }

        // $variable->method()
        if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
            return $this->currentMethodVariables[$var->name] ?? null;
        }

        // $obj->getX()->method() - chained method calls
        if ($var instanceof Node\Expr\MethodCall || $var instanceof Node\Expr\NullsafeMethodCall) {
            if (!$var->name instanceof Node\Identifier) {
                return null;
            }

            $innerClass = $this->resolveCalleeClass($var->var);
            if ($innerClass === null) {
                return null;
            }

            $methodName = $var->name->toString();
            return $this->classHierarchy->resolveMethodReturnType($innerClass, $methodName);
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

    /**
     * Track variable assignments for data flow analysis.
     * Updates $currentMethodVariables with the type of the assigned expression.
     */
    private function processAssignment(Node\Expr\Assign $node): void
    {
        // Only track simple variable assignments: $var = ...
        if (!$node->var instanceof Node\Expr\Variable) {
            return;
        }

        if (!is_string($node->var->name)) {
            return; // Skip dynamic variable names like $$var
        }

        $variableName = $node->var->name;
        $type = $this->resolveExpressionType($node->expr);

        if ($type !== null) {
            $this->currentMethodVariables[$variableName] = $type;
        }
    }

    /**
     * Resolve the type of an expression for data flow analysis.
     */
    private function resolveExpressionType(Node\Expr $expr): ?string
    {
        // Case 1: new ClassName()
        if ($expr instanceof Node\Expr\New_) {
            if ($expr->class instanceof Node\Name) {
                return $this->resolveName($expr->class);
            }
            return null; // Dynamic class: new $className()
        }

        // Case 2: $this->property or $this->obj->property (nested property access)
        if ($expr instanceof Node\Expr\PropertyFetch && $expr->name instanceof Node\Identifier) {
            $propertyName = $expr->name->toString();

            // $this->property
            if (
                $expr->var instanceof Node\Expr\Variable
                && $expr->var->name === 'this'
                && $this->currentClass !== null
            ) {
                return $this->typeRegistry->resolvePropertyType($this->currentClass, $propertyName);
            }

            // $this->obj->property or $var->property (nested/chained property access)
            $ownerClass = $this->resolveCalleeClass($expr->var);
            if ($ownerClass !== null) {
                return $this->typeRegistry->resolvePropertyType($ownerClass, $propertyName);
            }

            return null;
        }

        // Case 3: $otherVar (copy type from existing variable)
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $this->currentMethodVariables[$expr->name] ?? null;
        }

        // Case 4: $this->method() or $var->method() - instance method call
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            if (!$expr->name instanceof Node\Identifier) {
                return null;
            }

            $calleeClass = $this->resolveCalleeClass($expr->var);
            if ($calleeClass === null) {
                return null;
            }

            $methodName = $expr->name->toString();
            return $this->classHierarchy->resolveMethodReturnType($calleeClass, $methodName);
        }

        // Case 5: ClassName::staticMethod()
        if ($expr instanceof Node\Expr\StaticCall) {
            if (!$expr->class instanceof Node\Name || !$expr->name instanceof Node\Identifier) {
                return null;
            }

            $className = $this->resolveName($expr->class);
            $methodName = $expr->name->toString();
            return $this->classHierarchy->resolveMethodReturnType($className, $methodName);
        }

        // Case 6: Ternary - take first non-null type (conservative)
        if ($expr instanceof Node\Expr\Ternary) {
            $ifType = $expr->if !== null ? $this->resolveExpressionType($expr->if) : null;
            $elseType = $this->resolveExpressionType($expr->else);
            return $ifType ?? $elseType;
        }

        // Case 7: clone $expr - preserves type
        if ($expr instanceof Node\Expr\Clone_) {
            return $this->resolveExpressionType($expr->expr);
        }

        // Case 8: $x ?? $y - null coalescing (take first non-null type)
        if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            $leftType = $this->resolveExpressionType($expr->left);
            $rightType = $this->resolveExpressionType($expr->right);
            return $leftType ?? $rightType;
        }

        // Case 9: self::$property or ClassName::$property - static property
        if ($expr instanceof Node\Expr\StaticPropertyFetch && $expr->name instanceof Node\VarLikeIdentifier) {
            if (!$expr->class instanceof Node\Name) {
                return null;
            }

            $className = $this->resolveName($expr->class);
            $propertyName = $expr->name->toString();
            return $this->typeRegistry->resolvePropertyType($className, $propertyName);
        }

        return null;
    }
}
