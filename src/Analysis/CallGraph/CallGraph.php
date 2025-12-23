<?php

declare(strict_types=1);

namespace Guardrail\Analysis\CallGraph;

/**
 * Represents the call graph of a codebase.
 */
final class CallGraph
{
    /** @var array<string, list<MethodCall>> Calls from each method */
    private array $outgoingCalls = [];

    /** @var array<string, list<MethodCall>> Calls to each method */
    private array $incomingCalls = [];

    public function addCall(MethodCall $call): void
    {
        $callerId = $call->getCallerIdentifier();
        $this->outgoingCalls[$callerId] ??= [];
        $this->outgoingCalls[$callerId][] = $call;

        if ($call->calleeClass !== null) {
            $calleeId = $call->getCalleeIdentifier();
            $this->incomingCalls[$calleeId] ??= [];
            $this->incomingCalls[$calleeId][] = $call;
        }
    }

    /**
     * @return list<MethodCall>
     */
    public function getCallsFrom(string $className, string $methodName): array
    {
        $id = $className . '::' . $methodName;
        return $this->outgoingCalls[$id] ?? [];
    }

    /**
     * @return list<MethodCall>
     */
    public function getCallsTo(string $className, string $methodName): array
    {
        $id = $className . '::' . $methodName;
        return $this->incomingCalls[$id] ?? [];
    }

    /**
     * Check if a method (directly or indirectly) calls the target method.
     *
     * @param list<string> $visited Used to prevent infinite recursion
     */
    public function hasPathTo(
        string $fromClass,
        string $fromMethod,
        string $toClass,
        string $toMethod,
        array $visited = [],
    ): bool {
        $fromId = $fromClass . '::' . $fromMethod;

        if (in_array($fromId, $visited, strict: true)) {
            return false;
        }

        $visited[] = $fromId;
        $calls = $this->getCallsFrom($fromClass, $fromMethod);

        foreach ($calls as $call) {
            // Direct match
            if ($call->calleeClass === $toClass && $call->calleeMethod === $toMethod) {
                return true;
            }

            // Recursive search
            if ($call->calleeClass !== null) {
                if ($this->hasPathTo($call->calleeClass, $call->calleeMethod, $toClass, $toMethod, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find the path from one method to another.
     *
     * @param list<string> $visited
     * @return list<MethodCall>|null
     */
    public function findPathTo(
        string $fromClass,
        string $fromMethod,
        string $toClass,
        string $toMethod,
        array $visited = [],
    ): ?array {
        $fromId = $fromClass . '::' . $fromMethod;

        if (in_array($fromId, $visited, strict: true)) {
            return null;
        }

        $visited[] = $fromId;
        $calls = $this->getCallsFrom($fromClass, $fromMethod);

        foreach ($calls as $call) {
            // Direct match
            if ($call->calleeClass === $toClass && $call->calleeMethod === $toMethod) {
                return [$call];
            }

            // Recursive search
            if ($call->calleeClass !== null) {
                $path = $this->findPathTo($call->calleeClass, $call->calleeMethod, $toClass, $toMethod, $visited);
                if ($path !== null) {
                    return [$call, ...$path];
                }
            }
        }

        return null;
    }

    /**
     * Get all methods in the call graph.
     *
     * @return list<string>
     */
    public function getAllMethods(): array
    {
        return array_values(array_unique([
            ...array_keys($this->outgoingCalls),
            ...array_keys($this->incomingCalls),
        ]));
    }
}
