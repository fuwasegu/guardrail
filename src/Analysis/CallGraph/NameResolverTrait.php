<?php

declare(strict_types=1);

namespace Guardrail\Analysis\CallGraph;

use PhpParser\Node;

/**
 * Shared name and type resolution logic for AST visitors.
 *
 * @internal
 */
trait NameResolverTrait
{
    private ?string $currentNamespace = null;
    private ?string $currentClass = null;

    /** @var array<string, string> Short name => fully qualified name from use statements */
    private array $useStatements = [];

    protected function resolveType(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof Node\Name) {
            return $this->resolveName($type);
        }

        if ($type instanceof Node\Identifier) {
            return null;
        }

        if ($type instanceof Node\NullableType) {
            return $this->resolveType($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $t) {
                $resolved = $this->resolveType($t);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    protected function resolveName(Node\Name $name): string
    {
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        $nameStr = $name->toString();

        if (in_array($nameStr, ['self', 'static'], true)) {
            return $this->currentClass ?? $nameStr;
        }

        $firstPart = $name->getFirst();
        if (isset($this->useStatements[$firstPart])) {
            if (count($name->getParts()) === 1) {
                return $this->useStatements[$firstPart];
            } else {
                $remaining = array_slice($name->getParts(), 1);
                return $this->useStatements[$firstPart] . '\\' . implode('\\', $remaining);
            }
        }

        if ($this->currentNamespace !== null) {
            return $this->currentNamespace . '\\' . $nameStr;
        }

        return $nameStr;
    }
}
