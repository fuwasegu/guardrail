<?php

declare(strict_types=1);

namespace Guardrail\Analysis;

use Guardrail\Analysis\CallGraph\CallGraph;
use Guardrail\Analysis\CallGraph\CallGraphBuilder;
use Guardrail\Collector\EntryPoint;
use Guardrail\Config\MethodReference;
use Guardrail\Config\PairedCallRequirement;
use Guardrail\Config\PathCondition;
use Guardrail\Config\Rule;
use Guardrail\Config\ScanConfig;

/**
 * Main analyzer that checks rules against the codebase.
 */
final class Analyzer
{
    /** @var (callable(AnalyzerProgress): void)|null */
    private $progressCallback = null;

    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * @param callable(AnalyzerProgress): void $callback
     */
    public function onProgress(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * @param list<Rule> $rules
     * @param ScanConfig|null $scanConfig Optional scan configuration (defaults to src/app, excludes vendor)
     * @return list<RuleResult>
     */
    public function analyze(array $rules, ?ScanConfig $scanConfig = null): array
    {
        $this->reportProgress(AnalyzerProgress::buildingCallGraph());

        // Build call graph once for the analysis
        $callGraph = (new CallGraphBuilder())->build($this->basePath, $scanConfig);

        $this->reportProgress(AnalyzerProgress::callGraphBuilt());

        $results = [];
        foreach ($rules as $rule) {
            $results[] = $this->analyzeRule($rule, $callGraph);
        }

        return $results;
    }

    private function reportProgress(AnalyzerProgress $progress): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($progress);
        }
    }

    private function analyzeRule(Rule $rule, CallGraph $callGraph): RuleResult
    {
        // Use false for preserve_keys to avoid key collisions from yield from
        $entryPoints = iterator_to_array($rule->entryPointCollector->collect($this->basePath), preserve_keys: false);
        $total = count($entryPoints);
        $results = [];
        $pairedCallViolations = [];

        $this->reportProgress(AnalyzerProgress::analyzingRule($rule->name, 0, $total));

        foreach ($entryPoints as $index => $entryPoint) {
            // Check required calls (mustCall / mustCallAnyOf)
            if ($rule->requiredCalls !== []) {
                $results[] = $this->analyzeEntryPoint($entryPoint, $rule, $callGraph);
            }

            // Check paired call requirements (whenCalls -> mustAlsoCall)
            foreach ($rule->pairedCallRequirements as $requirement) {
                $violation = $this->checkPairedCallRequirement($entryPoint, $requirement, $callGraph);
                if ($violation !== null) {
                    $pairedCallViolations[] = $violation;
                }
            }

            $this->reportProgress(AnalyzerProgress::analyzingRule($rule->name, $index + 1, $total));
        }

        return new RuleResult($rule, $results, $pairedCallViolations);
    }

    private function analyzeEntryPoint(EntryPoint $entryPoint, Rule $rule, CallGraph $callGraph): AnalysisResult
    {
        // For MVP, we check if ANY of the required calls is reachable
        foreach ($rule->requiredCalls as $requiredCall) {
            $result = $this->checkRequiredCall($entryPoint, $requiredCall, $rule->pathCondition, $callGraph);
            if ($result->found) {
                return $result;
            }
        }

        // None of the required calls were found
        return new AnalysisResult(
            entryPoint: $entryPoint,
            requiredCall: $rule->requiredCalls[0],
            found: false,
            message: $rule->getDisplayMessage(),
        );
    }

    /**
     * @param PathCondition $condition Currently unused - OnAllPaths requires CFG analysis (not yet implemented)
     */
    private function checkRequiredCall(
        EntryPoint $entryPoint,
        MethodReference $requiredCall,
        PathCondition $condition,
        CallGraph $callGraph,
    ): AnalysisResult {
        $path = $callGraph->findPathTo(
            $entryPoint->className,
            $entryPoint->methodName,
            $requiredCall->className,
            $requiredCall->methodName,
        );

        if ($path !== null) {
            return new AnalysisResult(
                entryPoint: $entryPoint,
                requiredCall: $requiredCall,
                found: true,
                callPath: $path,
            );
        }

        return new AnalysisResult(entryPoint: $entryPoint, requiredCall: $requiredCall, found: false);
    }

    /**
     * Check if a paired call requirement is satisfied.
     *
     * Returns a violation if the trigger is called but none of the required methods are called.
     * Returns null if:
     *   - The trigger is not called (requirement doesn't apply)
     *   - The trigger is called AND at least one required method is also called
     */
    private function checkPairedCallRequirement(
        EntryPoint $entryPoint,
        PairedCallRequirement $requirement,
        CallGraph $callGraph,
    ): ?PairedCallViolation {
        // First, check if the trigger method is called
        $triggerPath = $callGraph->findPathTo(
            $entryPoint->className,
            $entryPoint->methodName,
            $requirement->trigger->className,
            $requirement->trigger->methodName,
        );

        // If trigger is not called, the requirement doesn't apply
        if ($triggerPath === null) {
            return null;
        }

        // Trigger is called - check if any of the required methods are also called
        foreach ($requirement->requiredCalls as $requiredCall) {
            $hasPath = $callGraph->hasPathTo(
                $entryPoint->className,
                $entryPoint->methodName,
                $requiredCall->className,
                $requiredCall->methodName,
            );

            if ($hasPath) {
                // At least one required method is called - requirement satisfied
                return null;
            }
        }

        // Trigger is called but none of the required methods are called - violation!
        return new PairedCallViolation(entryPoint: $entryPoint, requirement: $requirement, triggerPath: $triggerPath);
    }
}
